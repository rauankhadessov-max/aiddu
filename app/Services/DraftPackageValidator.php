<?php

namespace App\Services;

use RuntimeException;

class DraftPackageValidator
{
    public function validateJustifications(array $result, array $amendments): array
    {
        $items = $result['justifications'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('OpenAI вернул некорректную структуру обоснований СТ.');
        }

        $expected = collect($amendments)->keyBy('amendment_id');
        $validated = [];

        foreach ($items as $item) {
            $id = isset($item['amendment_id']) ? (int) $item['amendment_id'] : 0;
            if (! isset($expected[$id]) || isset($validated[$id])) {
                throw new RuntimeException('OpenAI вернул неизвестный или повторный amendment_id.');
            }

            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException("Для поправки {$id} отсутствует обоснование СТ.");
            }

            $ground = $this->groundText($expected[$id]);
            $this->assertNoUnsupportedFacts($text, $ground);
            $validated[$id] = [
                'text' => $text,
                'warnings' => array_values(array_filter(
                    is_array($item['warnings'] ?? null) ? $item['warnings'] : [],
                    fn ($warning) => is_string($warning) && trim($warning) !== '',
                )),
            ];
        }

        $missing = $expected->keys()->diff(array_keys($validated));
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('OpenAI не вернул обоснование для каждой подтверждённой поправки.');
        }

        return $validated;
    }

    public function hasSufficientGround(array $amendment): bool
    {
        return filled($amendment['analysis_justification'] ?? null)
            && filled($amendment['legal_basis'] ?? null)
            && ($amendment['citations'] ?? []) !== []
            && ($amendment['trusted_context'] ?? []) !== [];
    }

    private function groundText(array $amendment): string
    {
        return mb_strtolower(json_encode([
            $amendment['analysis_justification'] ?? null,
            $amendment['legal_basis'] ?? null,
            $amendment['warnings'] ?? [],
            $amendment['citations'] ?? [],
            collect($amendment['trusted_context'] ?? [])->pluck('text')->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function assertNoUnsupportedFacts(string $text, string $ground): void
    {
        preg_match_all(
            '/(?:Президент\p{L}*|Правительств\p{L}*|протокол\p{L}*|поручени\p{L}*|статистик\p{L}*|миллиард\p{L}*|миллион\p{L}*|№\s*[\p{L}\p{N}-]+)/ui',
            $text,
            $matches,
        );

        foreach ($matches[0] ?? [] as $claim) {
            if (! str_contains($ground, mb_strtolower(trim($claim)))) {
                throw new RuntimeException('Обоснование содержит неподтверждённое фактическое основание: '.$claim);
            }
        }
    }
}
