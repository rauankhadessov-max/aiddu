<?php

namespace App\Presenters;

use App\Models\AnalysisAmendment;
use Illuminate\Support\Collection;

class AnalysisResultPresenter
{
    private const SUFFICIENCY_LABELS = [
        'partial' => 'Нормативная база позволяет сделать только частичный вывод.',
        'insufficient' => 'Нормативной базы недостаточно для уверенного вывода.',
    ];

    private const WARNING_LABELS = [
        'existing_target_not_found' => 'Изменяемый структурный элемент не найден в выбранной нормативной базе.',
        'structural_anchor_not_found' => 'Не удалось подтвердить соседние структурные элементы.',
        'ambiguous_structural_scope' => 'Не удалось однозначно определить положение структурного элемента.',
        'ambiguous_target_source' => 'Статья найдена в нескольких подключённых НПА. Не удалось однозначно определить изменяемый нормативный акт. Уточните полное название целевого НПА.',
        'mandatory_context_budget_exceeded' => 'Обязательный нормативный контекст превышает доступный объём анализа.',
    ];

    private const SEVERITY_LABELS = [
        'critical' => 'Критический риск',
        'high' => 'Высокий риск',
        'medium' => 'Средний риск',
        'low' => 'Низкий риск',
        'info' => 'Информация',
    ];

    public function references(AnalysisAmendment $amendment): array
    {
        $reference = $this->cleanReference((string) $amendment->source_reference, $amendment);

        return collect(preg_split('/\s*;\s*/u', $reference) ?: [])
            ->map(fn (string $item) => trim(preg_replace('/\s{2,}/u', ' ', $item) ?? $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function groupedReferences(AnalysisAmendment $amendment, iterable $sourceVersions): array
    {
        $sourceTitles = collect($sourceVersions)
            ->filter(fn ($version) => $version->source !== null)
            ->mapWithKeys(fn ($version) => [(string) $version->source_id => $version->source->title]);
        $groups = collect();

        foreach ($amendment->citations ?? [] as $citation) {
            if (! is_array($citation)) {
                continue;
            }

            $sourceId = isset($citation['source_id']) ? (string) $citation['source_id'] : null;
            $sourceTitle = $sourceId ? $sourceTitles->get($sourceId) : null;

            if (! is_string($sourceTitle) || $sourceTitle === '') {
                $targetSourceId = (string) data_get($amendment->target_snapshot, 'source_id', '');
                $sourceTitle = $sourceId === $targetSourceId
                    ? data_get($amendment->target_snapshot, 'source_title')
                    : null;
            }

            if (! is_string($sourceTitle) || trim($sourceTitle) === '') {
                continue;
            }

            $key = $sourceId ?: mb_strtolower($sourceTitle);
            $group = $groups->get($key, [
                'source' => trim($sourceTitle),
                'locators' => [],
            ]);
            $group['locators'][] = [
                'article' => $this->locatorValue($citation['article'] ?? null),
                'paragraph' => $this->locatorValue($citation['paragraph'] ?? null),
                'subparagraph' => $this->locatorValue($citation['subparagraph'] ?? null),
            ];
            $groups->put($key, $group);
        }

        $references = $groups
            ->map(function (array $group) {
                $locators = $this->formatLocators($group['locators']);

                return $locators === []
                    ? $group['source']
                    : $group['source'].' — '.implode('; ', $locators);
            })
            ->values()
            ->all();

        return $references !== [] ? $references : $this->references($amendment);
    }

    private function cleanReference(string $reference, AnalysisAmendment $amendment): string
    {

        foreach ($amendment->citations ?? [] as $citation) {
            $fragmentId = is_array($citation) ? ($citation['fragment_id'] ?? null) : null;

            if (is_string($fragmentId) && $fragmentId !== '') {
                $reference = str_replace('['.$fragmentId.']', '', $reference);
            }
        }

        $reference = preg_replace('/\s*\[sv\d+-[a-z0-9]+\]/iu', '', $reference) ?? $reference;
        $reference = preg_replace('/\bредакция\s+Редакция\s+/iu', 'редакция ', $reference) ?? $reference;
        $reference = preg_replace('/,\s*Редакция\s+из\s+(?:официального|загруженного)\s+DOCX\b/iu', '', $reference) ?? $reference;

        return $reference;
    }

    private function locatorValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function formatLocators(array $locators): array
    {
        return collect($locators)
            ->unique(fn (array $locator) => implode('|', $locator))
            ->groupBy(fn (array $locator) => $locator['article'] ?? '')
            ->sortKeysUsing('strnatcasecmp')
            ->flatMap(function (Collection $articleLocators, $article) {
                $article = (string) $article;
                if ($article === '') {
                    return $articleLocators
                        ->map(fn (array $locator) => $this->formatLocator($locator))
                        ->filter()
                        ->unique()
                        ->values();
                }

                $result = collect();
                $paragraphsWithoutSubparagraphs = $articleLocators
                    ->filter(fn (array $locator) => $locator['paragraph'] !== null && $locator['subparagraph'] === null)
                    ->pluck('paragraph')
                    ->unique()
                    ->sort(fn (string $left, string $right) => strnatcasecmp($left, $right))
                    ->values();

                if ($paragraphsWithoutSubparagraphs->isNotEmpty()) {
                    $result->push('ст. '.$article.' п. '.$paragraphsWithoutSubparagraphs->implode(', '));
                }

                $articleLocators
                    ->filter(fn (array $locator) => $locator['subparagraph'] !== null)
                    ->groupBy(fn (array $locator) => $locator['paragraph'] ?? '')
                    ->sortKeysUsing('strnatcasecmp')
                    ->each(function (Collection $items, $paragraph) use ($article, $result) {
                        $paragraph = (string) $paragraph;
                        $subparagraphs = $items->pluck('subparagraph')
                            ->unique()
                            ->sort(fn (string $left, string $right) => strnatcasecmp($left, $right))
                            ->implode(', ');
                        $paragraphLabel = $paragraph !== '' ? ' п. '.$paragraph : '';
                        $result->push('ст. '.$article.$paragraphLabel.' пп. '.$subparagraphs);
                    });

                if ($result->isEmpty()) {
                    $result->push('ст. '.$article);
                }

                return $result;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function formatLocator(array $locator): ?string
    {
        if ($locator['paragraph'] !== null) {
            return 'п. '.$locator['paragraph'].($locator['subparagraph'] !== null ? ' пп. '.$locator['subparagraph'] : '');
        }

        return $locator['subparagraph'] !== null ? 'пп. '.$locator['subparagraph'] : null;
    }

    public function severity(?string $severity): string
    {
        return self::SEVERITY_LABELS[$severity ?? ''] ?? 'Юридическое замечание';
    }

    public function sufficiencyLabel(?string $status): ?string
    {
        return self::SUFFICIENCY_LABELS[$status] ?? null;
    }

    public function warning(mixed $warning): string
    {
        if (! is_string($warning) || trim($warning) === '') {
            return 'Требуется дополнительная юридическая проверка нормативного основания.';
        }

        $warning = trim($warning);

        if (preg_match('/article\s+([0-9]+(?:-[0-9]+)*)\s+matches multiple selected SourceVersions or scopes/i', $warning, $match) === 1) {
            return 'Статья '.$match[1].' найдена в нескольких подключённых НПА. Не удалось однозначно определить изменяемый нормативный акт. Уточните полное название целевого НПА.';
        }

        foreach (self::WARNING_LABELS as $code => $label) {
            if (str_contains($warning, $code)) {
                return $label;
            }
        }

        if (preg_match('/\b[a-z][a-z0-9]*(?:_[a-z0-9]+)+\b/i', $warning) === 1) {
            return 'Требуется дополнительная юридическая проверка нормативного основания.';
        }

        return trim(preg_replace('/\s*\[sv\d+-[a-z0-9]+\]/iu', '', $warning) ?? $warning);
    }

    public function warnings(array $warnings): array
    {
        $warnings = collect($warnings);
        $hasDetailedTargetAmbiguity = $warnings->contains(
            fn ($warning) => is_string($warning)
                && str_contains($warning, 'matches multiple selected SourceVersions or scopes'),
        );

        return $warnings
            ->when(
                $hasDetailedTargetAmbiguity,
                fn ($items) => $items->reject(fn ($warning) => trim((string) $warning) === 'ambiguous_target_source'),
            )
            ->map(fn ($warning) => $this->warning($warning))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
