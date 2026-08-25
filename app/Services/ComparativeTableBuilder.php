<?php

namespace App\Services;

class ComparativeTableBuilder
{
    public function build(array $input, array $justifications = []): array
    {
        $rows = [];
        $warnings = [];

        foreach ($input['amendment_snapshots'] as $index => $amendment) {
            $justification = trim((string) ($justifications[$amendment['amendment_id']]['text'] ?? ''));
            if ($justification === '') {
                $warnings[] = "Для поправки {$amendment['amendment_id']} требуется обоснование сравнительной таблицы.";
            }
            $rows[] = [
                'number' => $index + 1,
                'amendment_id' => $amendment['amendment_id'],
                'structural_element' => $amendment['target']['display'],
                'article_heading' => data_get($amendment, 'article_heading.text'),
                'current_text' => $amendment['target_mode'] === 'new'
                    ? config('legal_analysis.draft_package.current_text_absent_label')
                    : $amendment['current_text'],
                'proposed_text' => $amendment['proposed_text'],
                'justification' => $justification !== '' ? $justification : null,
                'warnings' => array_values(array_unique(array_merge(
                    $amendment['warnings'],
                    $justifications[$amendment['amendment_id']]['warnings'] ?? [],
                ))),
            ];
        }

        return [
            'schema_version' => 'comparative-table-v1',
            'title' => 'Сравнительная таблица',
            'columns' => ['№', 'Структурный элемент', 'Действующая редакция', 'Предлагаемая редакция', 'Обоснование'],
            'rows' => $rows,
            'warnings' => $warnings,
        ];
    }
}
