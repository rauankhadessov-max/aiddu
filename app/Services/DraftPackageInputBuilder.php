<?php

namespace App\Services;

use App\Models\Analysis;
use App\Models\AnalysisAmendment;
use Normalizer;
use RuntimeException;

class DraftPackageInputBuilder
{
    public function __construct(private readonly NpaTypeResolver $typeResolver) {}

    public function build(Analysis $analysis): array
    {
        $analysis->loadMissing([
            'document',
            'sourceVersions.source',
            'amendments.source',
            'amendments.sourceVersion',
        ]);

        if ($analysis->status !== 'completed') {
            throw new RuntimeException('Пакет документов можно сформировать только для завершённого анализа.');
        }

        if ($analysis->amendments->isEmpty()) {
            throw new RuntimeException('В анализе отсутствуют подтверждённые поправки.');
        }

        $sourceIds = $analysis->amendments->pluck('source_id')->unique()->values();
        if ($sourceIds->count() !== 1) {
            throw new RuntimeException('Поправки к нескольким самостоятельным НПА требуют выбора структуры пакета пользователем.');
        }

        $settings = $analysis->settings ?? [];
        $context = collect($settings['retrieval_context'] ?? [])->keyBy('fragment_id');
        if ($context->isEmpty()) {
            throw new RuntimeException('В snapshot анализа отсутствует trusted normative context.');
        }

        $source = $analysis->amendments->first()->source;
        $sourceSnapshot = [
            'source_id' => $source->id,
            'type' => $source->type,
            'title' => $source->title,
            'number' => $source->number,
            'adoption_date' => $source->adoption_date?->toDateString(),
            'issuing_authority' => $source->issuing_authority,
            'status' => $source->status,
        ];
        $profile = $this->typeResolver->resolve($sourceSnapshot);
        $attachedVersions = $analysis->sourceVersions->keyBy('id');
        $attachedVersionIds = $attachedVersions->keys()->map(fn ($id) => (int) $id)->all();
        $amendments = $analysis->amendments
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(fn (AnalysisAmendment $amendment) => $this->amendmentSnapshot(
                $amendment,
                $context->all(),
                $attachedVersionIds,
            ))
            ->values()
            ->all();
        $savedSourceSnapshots = collect($settings['source_snapshots'] ?? [])->keyBy('source_version_id');
        $usedVersionIds = collect($amendments)
            ->flatMap(fn (array $amendment) => collect($amendment['trusted_context'])->pluck('source_version_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $sourceSnapshots = $usedVersionIds
            ->map(function (int $versionId) use ($attachedVersions, $savedSourceSnapshots) {
                $version = $attachedVersions->get($versionId);

                if ($version === null) {
                    throw new RuntimeException("SourceVersion {$versionId} не подключён к Analysis.");
                }

                $source = $version->source;
                $saved = $savedSourceSnapshots->get($versionId, []);

                return [
                    'source_id' => $source->id,
                    'type' => $source->type,
                    'title' => $saved['source_title'] ?? $source->title,
                    'number' => $source->number,
                    'adoption_date' => $source->adoption_date?->toDateString(),
                    'issuing_authority' => $source->issuing_authority,
                    'status' => $source->status,
                    'source_version_id' => $versionId,
                    'version_name' => $saved['version_name'] ?? $version->version_name,
                    'effective_date' => $saved['effective_date'] ?? $version->effective_date?->toDateString(),
                    'source_version_hash' => $saved['source_version_hash'] ?? $version->hash,
                ];
            })
            ->all();

        $input = [
            'schema_version' => config('legal_analysis.draft_package.schema_version'),
            'generator_version' => config('legal_analysis.draft_package.generator_version'),
            'analysis_snapshot' => [
                'analysis_id' => $analysis->id,
                'analysis_version' => $analysis->version,
                'analysis_type' => $analysis->analysis_type,
                'title' => $analysis->title,
                'instruction' => $analysis->instruction,
                'completed_at' => $analysis->completed_at?->toISOString(),
                'context_hash' => $settings['context_hash'] ?? null,
                'prompt_version' => $settings['prompt_version'] ?? null,
                'retrieval_version' => $settings['retrieval_version'] ?? null,
                'validator_version' => $settings['validator_version'] ?? null,
            ],
            'source_snapshots' => $sourceSnapshots,
            'npa_profile' => $profile,
            'amendment_snapshots' => $amendments,
            'warnings' => array_values(array_unique(array_merge(
                $profile['warnings'],
                collect($amendments)->flatMap(fn (array $item) => $item['warnings'])->all(),
            ))),
        ];
        $input['input_hash'] = $this->hashPayload($input);

        return $input;
    }

    public function hashPayload(array $payload): string
    {
        unset($payload['input_hash']);
        $this->sortRecursively($payload);

        return hash('sha256', json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function amendmentSnapshot(
        AnalysisAmendment $amendment,
        array $context,
        array $attachedVersionIds,
    ): array {
        $attachedVersions = array_fill_keys(array_map('intval', $attachedVersionIds), true);
        $targetFragmentIds = array_values(array_unique(array_merge(
            $amendment->target_fragment_ids ?? [],
            $amendment->anchor_fragment_ids ?? [],
            collect($amendment->citations ?? [])
                ->whereIn('purpose', ['target_current_text', 'parent_anchor'])
                ->pluck('fragment_id')
                ->filter()
                ->all(),
        )));
        $targetContext = [];

        foreach ($targetFragmentIds as $fragmentId) {
            $fragment = $this->trustedFragment($fragmentId, $context, $attachedVersions);

            if ((int) ($fragment['source_version_id'] ?? 0) !== $amendment->source_version_id) {
                throw new RuntimeException("SourceVersion fragment {$fragmentId} не соответствует поправке.");
            }

            $targetContext[$fragmentId] = $fragment;
        }

        $legalBasisContext = [];

        foreach ($amendment->citations ?? [] as $citation) {
            $purpose = $citation['purpose'] ?? null;

            if (! in_array($purpose, ['target_current_text', 'parent_anchor', 'legal_basis', 'cross_reference'], true)) {
                throw new RuntimeException('Поправка содержит citation с неподдерживаемым purpose.');
            }

            $fragmentId = $citation['fragment_id'] ?? null;
            $fragment = $this->trustedFragment($fragmentId, $context, $attachedVersions);
            $this->validateCitation($citation, $fragment);

            if (in_array($purpose, ['target_current_text', 'parent_anchor'], true)) {
                if (! in_array($fragmentId, $targetFragmentIds, true)
                    || (int) $fragment['source_version_id'] !== $amendment->source_version_id) {
                    throw new RuntimeException('Target citation не соответствует target SourceVersion поправки.');
                }

                $targetContext[$fragmentId] = $fragment;

                continue;
            }

            $legalBasisContext[$fragmentId] = $fragment;
        }

        $trustedContext = $targetContext + $legalBasisContext;

        $target = $this->normalizedTarget($amendment);
        $operation = $this->normalizeOperation($amendment->amendment_type);
        $deletion = $operation === 'delete_element';

        if ($amendment->target_mode === 'existing' && blank($amendment->current_text)) {
            throw new RuntimeException('Для существующей нормы отсутствует trusted current text.');
        }
        if (! $deletion && blank($amendment->proposed_text)) {
            throw new RuntimeException('Для поправки отсутствует validated proposed text.');
        }

        return [
            'amendment_id' => $amendment->id,
            'sort_order' => $amendment->sort_order,
            'source_id' => $amendment->source_id,
            'source_version_id' => $amendment->source_version_id,
            'target_mode' => $amendment->target_mode,
            'target' => $target,
            'operation' => $operation,
            'original_amendment_type' => $amendment->amendment_type,
            'disposition' => $amendment->disposition,
            'current_text' => $amendment->target_mode === 'new' ? null : $amendment->current_text,
            'proposed_text' => $amendment->proposed_text,
            'analysis_justification' => $amendment->justification,
            'legal_basis' => $amendment->legal_basis,
            'source_reference' => $amendment->source_reference,
            'confidence_score' => $amendment->confidence_score,
            'warnings' => $amendment->warnings ?? [],
            'citations' => $amendment->citations ?? [],
            'target_snapshot' => $amendment->target_snapshot,
            'trusted_target_context' => array_values($targetContext),
            'trusted_legal_basis_context' => array_values($legalBasisContext),
            'trusted_context' => array_values($trustedContext),
        ];
    }

    private function trustedFragment(mixed $fragmentId, array $context, array $attachedVersions): array
    {
        if (! is_string($fragmentId) || $fragmentId === '' || ! is_array($context[$fragmentId] ?? null)) {
            throw new RuntimeException("Fragment {$fragmentId} отсутствует в snapshot анализа.");
        }

        $fragment = $context[$fragmentId];
        $sourceVersionId = (int) ($fragment['source_version_id'] ?? 0);

        if (! isset($attachedVersions[$sourceVersionId])) {
            throw new RuntimeException("SourceVersion fragment {$fragmentId} не подключён к Analysis.");
        }

        $textHash = (string) ($fragment['text_hash'] ?? '');
        if ($textHash === '' || ! hash_equals($textHash, hash('sha256', (string) ($fragment['text'] ?? '')))) {
            throw new RuntimeException("Нарушена целостность fragment {$fragmentId}.");
        }

        return $fragment;
    }

    private function validateCitation(array $citation, array $fragment): void
    {
        $fragmentId = (string) ($citation['fragment_id'] ?? '');

        foreach (['source_id', 'source_version_id', 'text_hash'] as $field) {
            if (! array_key_exists($field, $citation)
                || (string) $citation[$field] !== (string) ($fragment[$field] ?? '')) {
                throw new RuntimeException("Citation {$fragmentId} содержит несовпадающий {$field}.");
            }
        }

        $quote = $this->normalize((string) ($citation['quote'] ?? ''));
        if ($quote === '' || ! str_contains($this->normalize((string) $fragment['text']), $quote)) {
            throw new RuntimeException('Поправка содержит citation, не подтверждённую snapshot анализа.');
        }

        foreach (['article', 'paragraph', 'subparagraph'] as $locator) {
            $claimed = $citation[$locator] ?? null;
            $trusted = $fragment[$locator] ?? null;

            if ($claimed === null || $claimed === '' || $trusted === null) {
                continue;
            }

            if ($this->normalizeLocator((string) $claimed) !== $this->normalizeLocator((string) $trusted)) {
                throw new RuntimeException("Citation {$fragmentId} содержит несовпадающий {$locator}.");
            }
        }
    }

    private function normalizedTarget(AnalysisAmendment $amendment): array
    {
        $target = collect([
            'section' => $amendment->section,
            'chapter' => $amendment->chapter,
            'part' => $amendment->part,
            'article' => $amendment->article,
            'paragraph' => $amendment->paragraph,
            'subparagraph' => $amendment->subparagraph,
            'text_paragraph' => $amendment->text_paragraph,
            'appendix' => $amendment->appendix,
        ])->filter(fn ($value) => filled($value))->all();

        if ($amendment->target_mode === 'new') {
            $locator = $this->parseProposedLocator((string) $amendment->proposed_locator);
            $type = $amendment->structural_element_type;
            if (! isset($locator[$type])) {
                throw new RuntimeException('Новый structural target нельзя однозначно восстановить из proposed_locator.');
            }
            $target[$type] = $locator[$type];
        }

        return [
            'type' => $amendment->structural_element_type,
            'locators' => $target,
            'proposed_locator' => $amendment->proposed_locator,
            'display' => $this->displayLocator($amendment->structural_element_type, $target),
        ];
    }

    private function parseProposedLocator(string $value): array
    {
        $patterns = [
            'section' => '/(?:раздел|бөлім)\s+([\p{L}\p{N}-]+)/ui',
            'chapter' => '/(?:глава|тарау)\s+([\p{L}\p{N}-]+)/ui',
            'part' => '/(?:часть|бөлік)\s+([\p{L}\p{N}-]+)/ui',
            'article' => '/(?:статья|бап)\s+([\p{L}\p{N}-]+)/ui',
            'paragraph' => '/(?:пункт|тармақ)\s+([\p{L}\p{N}-]+)/ui',
            'subparagraph' => '/(?:подпункт|тармақша)\s+([\p{L}\p{N}-]+)/ui',
            'text_paragraph' => '/(?:абзац)\s+([\p{L}\p{N}-]+)/ui',
            'appendix' => '/(?:приложение|қосымша)\s+([\p{L}\p{N}-]+)/ui',
        ];
        $result = [];
        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $value, $matches) === 1) {
                $result[$key] = rtrim($matches[1], '.)');
            }
        }

        return $result;
    }

    private function displayLocator(string $type, array $locators): string
    {
        $labels = [
            'section' => 'раздел', 'chapter' => 'глава', 'part' => 'часть',
            'article' => 'статья', 'paragraph' => 'пункт', 'subparagraph' => 'подпункт',
            'text_paragraph' => 'абзац', 'appendix' => 'приложение',
        ];
        $parts = [];
        foreach (['section', 'chapter', 'article', 'part', 'paragraph', 'subparagraph', 'text_paragraph', 'appendix'] as $key) {
            if (isset($locators[$key])) {
                $parts[] = $labels[$key].' '.$locators[$key];
            }
            if ($key === $type && $parts !== []) {
                break;
            }
        }

        return implode(', ', $parts);
    }

    private function normalizeOperation(string $type): string
    {
        return match ($type) {
            'add_element' => 'add_element',
            'new_edition' => 'new_edition',
            'repeal_element', 'delete_element' => 'delete_element',
            'exclude_text', 'supplement_text', 'amend_text' => 'amend_text',
            default => throw new RuntimeException("Неподдерживаемый тип поправки: {$type}."),
        };
    }

    private function normalize(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower(trim($value));
    }

    private function normalizeLocator(string $value): string
    {
        $value = $this->normalize($value);
        $value = preg_replace(
            '/\b(?:статья|статьи|статье|пункт|пункта|пункте|подпункт|подпункта|подпункте|бап|тармақ|тармақша)\b/u',
            '',
            $value,
        ) ?? $value;

        return preg_replace('/[^\p{L}\p{N}-]+/u', '', $value) ?? $value;
    }

    private function sortRecursively(array &$value): void
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }
}
