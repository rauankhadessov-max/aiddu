<?php

namespace App\Services;

class LegalDocumentFormatter
{
    public const PRESENTATION_VERSION = 'legal-html-v2';

    public function requirementLabel(string $key): string
    {
        return (string) config(
            "legal_analysis.draft_package.requirement_labels.{$key}",
            config('legal_analysis.draft_package.unknown_requirement_label'),
        );
    }

    public function compactLocator(array $target, string $fallback): string
    {
        $type = (string) ($target['type'] ?? '');
        $locators = is_array($target['locators'] ?? null) ? $target['locators'] : [];
        if ($type === '' || ! isset($locators[$type])) {
            return $fallback;
        }

        $keys = match ($type) {
            'section' => ['section'],
            'chapter' => ['section', 'chapter'],
            'article' => ['article'],
            'part' => isset($locators['article']) ? ['article', 'part'] : ['chapter', 'part'],
            'paragraph' => $this->parentKeys($locators, ['paragraph']),
            'subparagraph' => $this->parentKeys($locators, ['paragraph', 'subparagraph']),
            'text_paragraph' => $this->parentKeys($locators, ['paragraph', 'subparagraph', 'text_paragraph']),
            'appendix' => ['appendix'],
            default => [],
        };
        if ($keys === []) {
            return $fallback;
        }

        $labels = [
            'section' => 'раздел',
            'chapter' => 'глава',
            'article' => 'статья',
            'part' => 'часть',
            'paragraph' => 'пункт',
            'subparagraph' => 'подпункт',
            'text_paragraph' => 'абзац',
            'appendix' => 'приложение',
        ];
        $parts = [];
        foreach ($keys as $key) {
            if (! isset($locators[$key]) || ! isset($labels[$key])) {
                continue;
            }
            $value = trim((string) $locators[$key]);
            if ($key === 'subparagraph') {
                $value = rtrim($value, ".) \t\n\r\0\x0B").')';
            }
            $parts[] = $labels[$key].' '.$value;
        }

        return $parts !== [] ? implode(', ', $parts) : $fallback;
    }

    public function textBlocks(?string $text): array
    {
        $text = (string) $text;
        if ($text === '') {
            return [];
        }

        $blocks = preg_split('/\R+/u', $text);
        if ($blocks === false) {
            return [$text];
        }

        $blocks = array_values(array_filter($blocks, fn (string $block) => $block !== ''));

        return $blocks !== [] ? $blocks : [$text];
    }

    public function commandBlocks(string $text): array
    {
        if (! str_contains($text, "\n") && ! str_contains($text, "\r")) {
            return [['kind' => 'raw', 'text' => $text]];
        }

        $blocks = $this->textBlocks($text);
        if (count($blocks) < 2) {
            return [['kind' => 'raw', 'text' => $text]];
        }

        return array_map(function (string $block, int $index): array {
            $trimmed = ltrim($block);
            $kind = $index === 0 ? 'instruction' : 'normative';
            if (str_starts_with($trimmed, '«')) {
                $kind = 'quotation_start';
            } elseif (preg_match('/^Статья\s+[\p{L}\p{N}-]+\./ui', $trimmed) === 1) {
                $kind = 'norm_heading';
            } elseif (preg_match('/^\d+[.-]/u', $trimmed) === 1) {
                $kind = 'norm_item';
            }

            return ['kind' => $kind, 'text' => $block];
        }, $blocks, array_keys($blocks));
    }

    public function typographicHeading(?string $text): ?string
    {
        if ($text === null || ! str_contains($text, '"')) {
            return $text;
        }

        $result = preg_replace('/"([^"\r\n]+)"/u', '«$1»', $text);

        return $result ?? $text;
    }

    private function parentKeys(array $locators, array $tail): array
    {
        $prefix = [];
        if (isset($locators['appendix'])) {
            $prefix[] = 'appendix';
        }
        if (isset($locators['article'])) {
            $prefix[] = 'article';
        } elseif (isset($locators['chapter'])) {
            $prefix[] = 'chapter';
        } elseif (isset($locators['section'])) {
            $prefix[] = 'section';
        }

        return array_values(array_unique(array_merge($prefix, $tail)));
    }
}
