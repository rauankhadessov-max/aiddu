<?php

namespace App\Services;

class LegalDocumentFormatter
{
    public const PRESENTATION_VERSION = 'legal-html-v3';

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

    public function compactCanonicalLocator(string $locator): string
    {
        $parts = preg_split('/\s*,\s*/u', trim($locator));
        if ($parts === false || count($parts) < 2) {
            return $this->normalizeSubparagraphLocator($locator);
        }

        foreach ($parts as $index => $part) {
            if (preg_match('/^(?:статья|бап)\s+/ui', $part) === 1) {
                return $this->normalizeSubparagraphLocator(implode(', ', array_slice($parts, $index)));
            }
        }

        return $this->normalizeSubparagraphLocator($locator);
    }

    public function currentTextBlocks(?string $text, string $structuralElement): array
    {
        $text = (string) $text;
        if (! $this->isAbsentText($text)) {
            return $this->textBlocks($text);
        }

        $target = $this->targetLocator($structuralElement);
        if ($target === null) {
            return $this->textBlocks($text);
        }

        [$type, $label, $value] = $target;
        $display = match ($type) {
            'article' => $this->upperFirst($label).' '.$value.'. Отсутствует.',
            'paragraph' => rtrim($value, ".) \t\n\r\0\x0B").'. Отсутствует.',
            'subparagraph' => rtrim($value, ".) \t\n\r\0\x0B").') отсутствует.',
            default => $this->upperFirst($label).' '.$value.'. Отсутствует.',
        };

        return [$display];
    }

    public function proposedTextBlocks(?string $text, string $structuralElement, ?string $currentText): array
    {
        $blocks = $this->textBlocks($text);
        if ($blocks === [] || ! $this->isAbsentText((string) $currentText)) {
            return $blocks;
        }

        $target = $this->targetLocator($structuralElement);
        if ($target === null || $this->startsWithTargetLocator((string) $text, $target)) {
            return $blocks;
        }

        [$type, $label, $value] = $target;
        $prefix = match ($type) {
            'article' => $this->upperFirst($label).' '.$value.'.',
            'paragraph' => rtrim($value, ".) \t\n\r\0\x0B").'.',
            'subparagraph' => rtrim($value, ".) \t\n\r\0\x0B").')',
            default => $this->upperFirst($label).' '.$value.'.',
        };

        return array_merge([$prefix], $blocks);
    }

    public function comparativeTableHeading(?array $draftNpa): array
    {
        $lines = ['СРАВНИТЕЛЬНАЯ ТАБЛИЦА'];
        if (! is_array($draftNpa)) {
            return $lines;
        }

        $actType = trim((string) ($draftNpa['act_type'] ?? ''));
        if ($actType !== '') {
            $lines[] = 'к проекту '.$this->genitiveActType($actType);
        }

        $title = trim((string) ($this->typographicHeading($draftNpa['title'] ?? null) ?? ''));
        if ($title !== '') {
            $lines[] = '«'.$title.'»';
        }

        return $lines;
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

    private function isAbsentText(string $text): bool
    {
        $configured = (string) config('legal_analysis.draft_package.current_text_absent_label', 'Отсутствует');
        $normalize = static fn (string $value): string => mb_strtolower(
            trim(preg_replace('/[\s.]+$/u', '', trim($value)) ?? trim($value)),
        );

        return $normalize($text) === $normalize($configured);
    }

    private function targetLocator(string $structuralElement): ?array
    {
        $parts = preg_split('/\s*,\s*/u', trim($structuralElement));
        if ($parts === false || $parts === []) {
            return null;
        }

        $target = trim((string) end($parts));
        $patterns = [
            'subparagraph' => '/^(подпункт|тармақша)\s+([\p{L}\p{N}.-]+)\)?$/ui',
            'paragraph' => '/^(пункт|тармақ)\s+([\p{L}\p{N}.-]+)\.?$/ui',
            'article' => '/^(статья|бап)\s+([\p{L}\p{N}.-]+)\.?$/ui',
            'part' => '/^(часть|бөлік)\s+([\p{L}\p{N}.-]+)\.?$/ui',
            'chapter' => '/^(глава|тарау)\s+([\p{L}\p{N}.-]+)\.?$/ui',
            'section' => '/^(раздел|бөлім)\s+([\p{L}\p{N}.-]+)\.?$/ui',
            'appendix' => '/^(приложение|қосымша)\s+([\p{L}\p{N}.-]+)\.?$/ui',
            'text_paragraph' => '/^(абзац)\s+([\p{L}\p{N}.-]+)\.?$/ui',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $target, $matches) === 1) {
                return [$type, mb_strtolower($matches[1]), rtrim($matches[2], '.')];
            }
        }

        return null;
    }

    private function startsWithTargetLocator(string $text, array $target): bool
    {
        [$type, $label, $value] = $target;
        $value = preg_quote(rtrim($value, ".) \t\n\r\0\x0B"), '/');
        $label = preg_quote($label, '/');
        $leading = '^\s*[«„"]?\s*';

        $pattern = match ($type) {
            'article', 'part', 'chapter', 'section', 'appendix', 'text_paragraph' => "/{$leading}{$label}\s+{$value}(?:\s*[.)]|\b)/ui",
            'paragraph' => "/{$leading}{$value}\s*[.)]/u",
            'subparagraph' => "/{$leading}{$value}\s*\)/u",
            default => null,
        };

        return $pattern !== null && preg_match($pattern, $text) === 1;
    }

    private function normalizeSubparagraphLocator(string $locator): string
    {
        $normalized = preg_replace(
            '/(\b(?:подпункт|тармақша)\s+[\p{L}\p{N}.-]+)\)?(?=\s*(?:,|$))/ui',
            '$1)',
            $locator,
        );

        return $normalized ?? $locator;
    }

    private function genitiveActType(string $actType): string
    {
        $forms = [
            '/^Закон\b/u' => 'Закона',
            '/^Кодекс\b/u' => 'Кодекса',
            '/^Приказ\b/u' => 'Приказа',
            '/^Постановление\b/u' => 'Постановления',
            '/^Правила\b/u' => 'Правил',
            '/^Методика\b/u' => 'Методики',
        ];

        foreach ($forms as $pattern => $replacement) {
            if (preg_match($pattern, $actType) === 1) {
                return preg_replace($pattern, $replacement, $actType, 1) ?? $actType;
            }
        }

        return $actType;
    }

    private function upperFirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)).mb_substr($value, 1);
    }
}
