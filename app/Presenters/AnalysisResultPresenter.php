<?php

namespace App\Presenters;

use App\Models\AnalysisAmendment;

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

    public function references(AnalysisAmendment $amendment): array
    {
        $reference = (string) $amendment->source_reference;

        foreach ($amendment->citations ?? [] as $citation) {
            $fragmentId = is_array($citation) ? ($citation['fragment_id'] ?? null) : null;

            if (is_string($fragmentId) && $fragmentId !== '') {
                $reference = str_replace('['.$fragmentId.']', '', $reference);
            }
        }

        $reference = preg_replace('/\s*\[sv\d+-[a-z0-9]+\]/iu', '', $reference) ?? $reference;

        return collect(preg_split('/\s*;\s*/u', $reference) ?: [])
            ->map(fn (string $item) => trim(preg_replace('/\s{2,}/u', ' ', $item) ?? $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function sufficiencyLabel(?string $status): ?string
    {
        return self::SUFFICIENCY_LABELS[$status] ?? null;
    }

    public function warning(mixed $warning): string
    {
        if (!is_string($warning) || trim($warning) === '') {
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
