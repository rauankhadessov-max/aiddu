<?php

namespace App\Services;

use RuntimeException;

class AmendmentCommandCompiler
{
    public function compile(array $amendment): array
    {
        $target = $amendment['target'];
        $type = $target['type'];
        $locator = $target['locators'][$type] ?? null;
        if ($locator === null) {
            throw new RuntimeException('У поправки отсутствует нормализованный structural locator.');
        }

        return match ($amendment['operation']) {
            'add_element' => $this->addElement($amendment, (string) $locator),
            'new_edition' => $this->newEdition($amendment),
            'delete_element' => [
                'text' => ucfirst($target['display']).' исключить;',
                'warnings' => [],
            ],
            'amend_text' => [
                'text' => $this->editionTarget($target)." изложить в следующей редакции:\n\n«".$this->quoteText($amendment['proposed_text']).'»;',
                'warnings' => ['Точечный текстовый delta не сохранён; используется безопасная новая редакция структурного элемента.'],
            ],
            default => throw new RuntimeException('Неподдерживаемая операция проекта НПА.'),
        };
    }

    private function addElement(array $amendment, string $locator): array
    {
        $target = $amendment['target'];
        $locators = $target['locators'];
        $quoted = '«'.$this->quoteText($amendment['proposed_text']).'»;';
        $text = match ($target['type']) {
            'subparagraph' => sprintf(
                "В статье %s:\n\nпункт %s дополнить подпунктом %s) следующего содержания:\n\n%s",
                $locators['article'] ?? '?',
                $locators['paragraph'] ?? '?',
                $locator,
                $quoted,
            ),
            'paragraph' => isset($locators['article'])
                ? sprintf("Статью %s дополнить пунктом %s следующего содержания:\n\n%s", $locators['article'], $locator, $quoted)
                : sprintf("Дополнить пунктом %s следующего содержания:\n\n%s", $locator, $quoted),
            'article' => sprintf("Дополнить статьей %s следующего содержания:\n\n%s", $locator, $quoted),
            'chapter' => sprintf("Дополнить главой %s следующего содержания:\n\n%s", $locator, $quoted),
            'section' => sprintf("Дополнить разделом %s следующего содержания:\n\n%s", $locator, $quoted),
            'part' => sprintf("%s дополнить частью %s следующего содержания:\n\n%s", ucfirst($this->parentDisplay($locators, ['article', 'chapter', 'section'])), $locator, $quoted),
            'text_paragraph' => sprintf("%s дополнить абзацем %s следующего содержания:\n\n%s", ucfirst($this->parentDisplay($locators, ['paragraph', 'article'])), $locator, $quoted),
            'appendix' => sprintf("Дополнить приложением %s следующего содержания:\n\n%s", $locator, $quoted),
            default => throw new RuntimeException('Неподдерживаемый structural level для дополнения.'),
        };

        return ['text' => $text, 'warnings' => str_contains($text, '?') ? ['Не определён parent structural element.'] : []];
    }

    private function newEdition(array $amendment): array
    {
        return [
            'text' => $this->editionTarget($amendment['target'])." изложить в следующей редакции:\n\n«".$this->quoteText($amendment['proposed_text']).'»;',
            'warnings' => [],
        ];
    }

    private function editionTarget(array $target): string
    {
        $locators = $target['locators'] ?? [];
        $type = (string) ($target['type'] ?? '');
        $locator = (string) ($locators[$type] ?? '');

        return match ($type) {
            'article' => "Статью {$locator}",
            'paragraph' => isset($locators['article'])
                ? "Пункт {$locator} статьи {$locators['article']}"
                : "Пункт {$locator}",
            'subparagraph' => $this->subparagraphEditionTarget($locator, $locators),
            'part' => isset($locators['article'])
                ? "Часть {$locator} статьи {$locators['article']}"
                : "Часть {$locator}",
            'chapter' => "Главу {$locator}",
            'section' => "Раздел {$locator}",
            'appendix' => "Приложение {$locator}",
            default => ucfirst((string) ($target['display'] ?? 'структурный элемент')),
        };
    }

    private function subparagraphEditionTarget(string $locator, array $locators): string
    {
        $locator = rtrim($locator, ".) \t\n\r\0\x0B").')';
        $target = "Подпункт {$locator}";
        if (isset($locators['paragraph'])) {
            $target .= " пункта {$locators['paragraph']}";
        }
        if (isset($locators['article'])) {
            $target .= " статьи {$locators['article']}";
        }

        return $target;
    }

    private function parentDisplay(array $locators, array $priority): string
    {
        $labels = ['article' => 'статью', 'chapter' => 'главу', 'section' => 'раздел', 'paragraph' => 'пункт'];
        foreach ($priority as $key) {
            if (isset($locators[$key])) {
                return $labels[$key].' '.$locators[$key];
            }
        }

        return 'структурный элемент';
    }

    private function quoteText(?string $text): string
    {
        return trim((string) $text);
    }
}
