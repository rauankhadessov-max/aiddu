<?php

namespace App\Services;

use RuntimeException;

class NpaTypeResolver
{
    public function resolve(array $source): array
    {
        $type = $source['type'] ?? null;
        $authority = trim((string) ($source['issuing_authority'] ?? ''));
        $requires = [];
        $warnings = [];

        [$actType, $actTitle] = match ($type) {
            'law', 'code' => ['law', 'Закон Республики Казахстан'],
            'government_resolution' => ['government_resolution', 'Постановление Правительства Республики Казахстан'],
            'order' => ['order', $authority !== '' ? 'Приказ '.$authority : null],
            'rules' => [null, null],
            'other' => [null, null],
            default => throw new RuntimeException('Неизвестный вид изменяемого НПА.'),
        };

        if ($type === 'order' && $authority === '') {
            $requires[] = 'adopting_authority';
            $warnings[] = 'Не указан орган или должностное лицо, принимающее приказ.';
        }

        if ($type === 'rules') {
            $requires[] = 'approving_act';
            $warnings[] = 'Для Правил или Методики не указан утверждающий приказ либо постановление.';
        }

        if ($type === 'other') {
            $requires[] = 'adopting_act_type';
            $warnings[] = 'Вид принимающего акта невозможно надёжно определить по metadata источника.';
        }

        return [
            'target_npa' => $source,
            'adopting_act' => [
                'type' => $actType,
                'title' => $actTitle,
                'status' => $requires === [] ? 'resolved' : 'requires_user_input',
            ],
            'requires_user_input' => $requires,
            'warnings' => $warnings,
        ];
    }
}
