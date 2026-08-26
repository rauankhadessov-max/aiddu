<?php

namespace App\Services;

use App\Data\LegalDiscoveryResult;
use App\Data\LegalRetrievalResult;
use App\Models\Analysis;

class LegalDiscoveryService
{
    public function __construct(
        private readonly LegalRetrievalService $retrievalService,
        private readonly LegalStructureService $structureService,
        private readonly LegalStructuralDiscoveryService $structuralDiscoveryService,
        private readonly OpenAIService $openAIService,
    ) {}

    public function discover(Analysis $analysis): LegalDiscoveryResult
    {
        $allFragments = $this->retrievalService->allFragments($analysis);

        if ($allFragments === []) {
            return $this->insufficient('В выбранных редакциях отсутствует текст, пригодный для структурного поиска.');
        }

        $outline = $this->boundedOutline($this->structureService->outline($allFragments));
        $allowedIds = array_values(array_unique(array_merge(...array_map(
            fn (array $element) => $element['fragment_ids'],
            $outline,
        ))));

        if ($allowedIds === []) {
            return $this->insufficient('Не удалось надёжно построить структуру выбранных нормативных источников.');
        }

        $catalog = collect($outline)
            ->map(fn (array $element) => json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            ->implode("\n");
        $prompt = <<<PROMPT
Ты выполняешь только этап поиска норм в выбранных НПА Республики Казахстан.

ПОРУЧЕНИЕ:
{$analysis->instruction}

СТРУКТУРНЫЙ КАТАЛОГ:
{$catalog}

Раздели найденные fragment_id по назначению:
- target_candidate_fragment_ids — только вероятные структурные элементы НПА, которые требуется изменить;
- supporting_candidate_fragment_ids — нормы для проверки, обоснования и межотраслевых ссылок.
Не включай supporting fragment в target только потому, что он юридически релевантен.
requested_legal_issues должны кратко перечислять правовые вопросы, которые нужно проверить по выбранным источникам.
candidate_fragment_ids сохрани как совместимый объединённый список target и supporting candidates.
Не придумывай номера статей и названия НПА. Search queries используются только для локального поиска и не являются правовым выводом.
Если каталог не позволяет определить кандидатов, верни source_sufficiency=insufficient и конкретные warnings без названия выдуманного НПА.
PROMPT;
        $response = $this->openAIService->respondStructured(
            $prompt,
            $this->schema($allowedIds),
            [
                'schema_name' => 'legal_discovery_v2',
                'timeout' => config('legal_analysis.timeout_seconds', 180),
            ],
        );
        $result = $response['result'];
        $legacyCandidateIds = $this->filterCandidateIds($result['candidate_fragment_ids'] ?? [], $allowedIds);
        $hasSeparatedCandidates = array_key_exists('target_candidate_fragment_ids', $result)
            || array_key_exists('supporting_candidate_fragment_ids', $result);
        $targetCandidateIds = $this->filterCandidateIds($result['target_candidate_fragment_ids'] ?? [], $allowedIds);
        $supportingCandidateIds = $this->filterCandidateIds($result['supporting_candidate_fragment_ids'] ?? [], $allowedIds);

        if (! $hasSeparatedCandidates && $legacyCandidateIds !== []) {
            [$targetCandidateIds, $supportingCandidateIds] = $this->partitionLegacyCandidates(
                $legacyCandidateIds,
                $allFragments,
            );
        }

        $candidateIds = array_values(array_unique(array_merge(
            $legacyCandidateIds,
            $targetCandidateIds,
            $supportingCandidateIds,
        )));
        $queries = array_values(array_slice(array_filter(
            $result['search_queries'] ?? [],
            fn ($query) => is_string($query) && trim($query) !== '',
        ), 0, (int) config('legal_analysis.discovery.max_search_queries', 12)));
        $warnings = array_values(array_filter(
            $result['warnings'] ?? [],
            fn ($warning) => is_string($warning) && trim($warning) !== '',
        ));
        $requestedLegalIssues = array_values(array_slice(array_unique(array_filter(
            $result['requested_legal_issues'] ?? [],
            fn ($issue) => is_string($issue) && trim($issue) !== '',
        )), 0, (int) config('legal_analysis.discovery.max_legal_issues', 12)));
        $sufficiency = in_array($result['source_sufficiency'] ?? null, ['sufficient', 'partial', 'insufficient'], true)
            ? $result['source_sufficiency']
            : 'insufficient';
        $structuralPlan = $this->structuralDiscoveryService->planFromCandidates(
            $analysis,
            $targetCandidateIds,
            $candidateIds === [],
        );
        $retrieval = $this->retrievalService->retrieve(
            analysis: $analysis,
            additionalQuery: implode("\n", $queries),
            structuralPlan: $structuralPlan,
            supportingCandidateIds: $supportingCandidateIds,
            requestedLegalIssues: $requestedLegalIssues,
            supportingQueries: $queries,
        );

        if ($retrieval->isEmpty()) {
            $sufficiency = 'insufficient';
            $warnings[] = 'По выбранной нормативной базе не найден подтверждённый контекст для разработки поправок.';
        }

        if ($retrieval->contextSufficiency?->status === 'insufficient') {
            $sufficiency = 'insufficient';
            $warnings = array_merge(
                $warnings,
                $retrieval->contextSufficiency->reasons,
                $retrieval->contextSufficiency->missingElements,
            );
        } elseif ($sufficiency === 'insufficient' && $candidateIds !== [] && ! $retrieval->isEmpty()) {
            // Discovery sees only a bounded catalog. A successful deterministic retrieval may
            // establish usable context, but it cannot promote the result above partial.
            $sufficiency = 'partial';
        }

        return new LegalDiscoveryResult(
            retrieval: $retrieval,
            sourceSufficiency: $sufficiency,
            warnings: array_values(array_unique($warnings)),
            candidateFragmentIds: $candidateIds,
            searchQueries: $queries,
            responseId: $response['response_id'] ?? null,
            usage: $response['usage'] ?? [],
            model: $response['model'] ?? null,
            requestPayloadHash: $response['request_payload_hash'] ?? null,
            contextSufficiency: $retrieval->contextSufficiency?->toArray(),
            targetCandidateFragmentIds: $targetCandidateIds,
            supportingCandidateFragmentIds: $supportingCandidateIds,
            requestedLegalIssues: $requestedLegalIssues,
        );
    }

    private function boundedOutline(array $outline): array
    {
        $budget = (int) config('legal_analysis.discovery.outline_budget_chars', 50000);
        $selected = [];
        $used = 0;
        $groups = [];

        foreach ($outline as $element) {
            $groups[$element['source_version_id']][] = $element;
        }

        ksort($groups);

        while ($groups !== []) {
            $progress = false;

            foreach ($groups as $versionId => &$elements) {
                if ($elements === []) {
                    unset($groups[$versionId]);

                    continue;
                }

                $element = array_shift($elements);
                $size = mb_strlen(json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                if ($used + $size > $budget) {
                    continue;
                }

                $selected[] = $element;
                $used += $size;
                $progress = true;
            }
            unset($elements);

            if (! $progress) {
                break;
            }
        }

        return $selected;
    }

    private function schema(array $allowedIds): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'source_sufficiency' => ['type' => 'string', 'enum' => ['sufficient', 'partial', 'insufficient']],
                'search_queries' => ['type' => 'array', 'items' => ['type' => 'string']],
                'candidate_fragment_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $allowedIds],
                ],
                'target_candidate_fragment_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $allowedIds],
                ],
                'supporting_candidate_fragment_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => $allowedIds],
                ],
                'requested_legal_issues' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => [
                'source_sufficiency',
                'search_queries',
                'candidate_fragment_ids',
                'target_candidate_fragment_ids',
                'supporting_candidate_fragment_ids',
                'requested_legal_issues',
                'warnings',
            ],
        ];
    }

    private function filterCandidateIds(mixed $ids, array $allowedIds): array
    {
        return array_values(array_slice(array_unique(array_filter(
            is_array($ids) ? $ids : [],
            fn ($id) => is_string($id) && in_array($id, $allowedIds, true),
        )), 0, (int) config('legal_analysis.discovery.max_candidates', 16)));
    }

    private function partitionLegacyCandidates(array $candidateIds, array $allFragments): array
    {
        $candidateMap = array_fill_keys($candidateIds, true);
        $versionIds = collect($allFragments)
            ->filter(fn ($fragment) => isset($candidateMap[$fragment->fragmentId]))
            ->pluck('sourceVersionId')
            ->unique()
            ->values();

        if ($versionIds->count() === 1) {
            return [$candidateIds, []];
        }

        return [[], $candidateIds];
    }

    private function insufficient(string $warning): LegalDiscoveryResult
    {
        return new LegalDiscoveryResult(
            retrieval: new LegalRetrievalResult([], [], hash('sha256', ''), hash('sha256', '[]'), config('legal_analysis.retrieval_version'), 0),
            sourceSufficiency: 'insufficient',
            warnings: [$warning],
            candidateFragmentIds: [],
            searchQueries: [],
            responseId: null,
            usage: [],
            model: null,
            requestPayloadHash: null,
            contextSufficiency: null,
        );
    }
}
