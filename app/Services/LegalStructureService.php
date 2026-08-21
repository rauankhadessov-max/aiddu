<?php

namespace App\Services;

use App\Data\LegalContextFragment;
use App\Models\SourceVersion;

class LegalStructureService
{
    public function fragments(SourceVersion $version): array
    {
        $version->loadMissing('source');
        $text = (string) ($version->text ?? '');

        if (trim($text) === '') {
            return [];
        }

        $markers = $this->markers($text);

        if ($markers === []) {
            return $this->fallbackParagraphs($version, $text);
        }

        $fragments = [];
        $state = $this->emptyState();
        $cursor = 0;

        foreach ($markers as $index => $marker) {
            if ($marker['offset'] > $cursor) {
                array_push($fragments, ...$this->makeSizedFragments(
                    $version,
                    $text,
                    $cursor,
                    $marker['offset'],
                    $state,
                ));
            }

            $state = $this->applyMarker($state, $marker);
            $cursor = $marker['offset'];
            $nextOffset = $markers[$index + 1]['offset'] ?? strlen($text);

            array_push($fragments, ...$this->makeSizedFragments(
                $version,
                $text,
                $cursor,
                $nextOffset,
                $state,
            ));

            $cursor = $nextOffset;
        }

        return $this->deduplicate($fragments);
    }

    public function outline(array $fragments): array
    {
        $outline = [];

        foreach ($fragments as $fragment) {
            $key = implode('|', [
                $fragment->sourceVersionId,
                $fragment->appendix,
                $fragment->section,
                $fragment->chapter,
                $fragment->part,
                $fragment->article,
                $fragment->paragraph,
                $fragment->subparagraph,
                $fragment->textParagraph,
                $fragment->elementType,
            ]);

            if (isset($outline[$key])) {
                $outline[$key]['fragment_ids'][] = $fragment->fragmentId;

                continue;
            }

            $outline[$key] = [
                'source_id' => $fragment->sourceId,
                'source_version_id' => $fragment->sourceVersionId,
                'source_title' => $fragment->sourceTitle,
                'version_name' => $fragment->versionName,
                'appendix' => $fragment->appendix,
                'section' => $fragment->section,
                'chapter' => $fragment->chapter,
                'part' => $fragment->part,
                'article' => $fragment->article,
                'paragraph' => $fragment->paragraph,
                'subparagraph' => $fragment->subparagraph,
                'text_paragraph' => $fragment->textParagraph,
                'element_type' => $fragment->elementType,
                'element_label' => $fragment->elementLabel,
                'fragment_ids' => [$fragment->fragmentId],
                'preview' => mb_substr(preg_replace('/\s+/u', ' ', $fragment->text) ?? $fragment->text, 0, 240),
            ];
        }

        return array_values($outline);
    }

    public function locatorExists(array $fragments, int $sourceVersionId, array $locator): bool
    {
        foreach ($fragments as $fragment) {
            if ($fragment->sourceVersionId !== $sourceVersionId) {
                continue;
            }

            $matched = true;

            foreach (['appendix', 'section', 'chapter', 'part', 'article', 'paragraph', 'subparagraph', 'textParagraph'] as $field) {
                $locatorKey = $field === 'textParagraph' ? 'text_paragraph' : $field;
                if (($locator[$locatorKey] ?? null) === null) {
                    continue;
                }

                if ($this->normalizeLocator((string) $locator[$locatorKey]) !== $this->normalizeLocator((string) $fragment->{$field})) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    private function markers(string $text): array
    {
        $definitions = [
            'appendix' => '/^[\h]*(?:Приложение|ПРИЛОЖЕНИЕ)(?:\h+№?\h*([\p{L}\p{N}.-]+))?[^\r\n]*/imu',
            'section' => '/^[\h]*(?:Раздел|РАЗДЕЛ)\h+([IVXLCDM\d-]+)[^\r\n]*/imu',
            'chapter' => '/^[\h]*(?:Глава|ГЛАВА)\h+([\d-]+)[^\r\n]*/imu',
            'part' => '/^[\h]*(?:Часть|ЧАСТЬ)\h+([\d-]+)[^\r\n]*/imu',
            'article' => '/^[\h]*(?:Статья|СТАТЬЯ|Бап)\h+([\d]+(?:[-.]\d+)*)[^\r\n]*/imu',
            'paragraph' => '/^[\h]*([\d]+(?:-\d+)*)\.\h+[^\r\n]*/mu',
            'subparagraph' => '/^[\h]*([\d]+(?:-\d+)*)\)\h+[^\r\n]*/mu',
            'text_paragraph' => '/^[\h]*(?:Абзац|АБЗАЦ)\h+([\d]+(?:-\d+)*)[^\r\n]*/imu',
        ];
        $markers = [];

        foreach ($definitions as $type => $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
            $minimum = in_array($type, ['paragraph', 'subparagraph'], true)
                ? (int) config('legal_analysis.retrieval.reliable_locator_matches', 2)
                : 1;

            if (count($matches[0] ?? []) < $minimum) {
                continue;
            }

            foreach ($matches[0] as $index => $match) {
                $markers[] = [
                    'type' => $type,
                    'value' => trim((string) ($matches[1][$index][0] ?? '')) ?: null,
                    'label' => trim($match[0]),
                    'offset' => $match[1],
                ];
            }
        }

        usort($markers, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return $markers;
    }

    private function applyMarker(array $state, array $marker): array
    {
        $type = $marker['type'];

        if ($type === 'appendix') {
            $state = $this->emptyState();
            $state['appendix'] = $marker['value'] ?? $marker['label'];
        } elseif ($type === 'section') {
            $state['section'] = $marker['value'];
            $state['chapter'] = $state['part'] = $state['article'] = $state['paragraph'] = $state['subparagraph'] = $state['text_paragraph'] = null;
        } elseif ($type === 'chapter') {
            $state['chapter'] = $marker['value'];
            $state['part'] = $state['article'] = $state['paragraph'] = $state['subparagraph'] = $state['text_paragraph'] = null;
        } elseif ($type === 'part') {
            $state['part'] = $marker['value'];
            $state['article'] = $state['paragraph'] = $state['subparagraph'] = $state['text_paragraph'] = null;
        } elseif ($type === 'article') {
            $state['article'] = $marker['value'];
            $state['paragraph'] = $state['subparagraph'] = $state['text_paragraph'] = null;
        } elseif ($type === 'paragraph') {
            $state['paragraph'] = $marker['value'];
            $state['subparagraph'] = $state['text_paragraph'] = null;
        } elseif ($type === 'subparagraph') {
            $state['subparagraph'] = $marker['value'];
            $state['text_paragraph'] = null;
        } elseif ($type === 'text_paragraph') {
            $state['text_paragraph'] = $marker['value'];
        }

        $state['element_type'] = $type;
        $state['element_label'] = $marker['label'];

        return $state;
    }

    private function fallbackParagraphs(SourceVersion $version, string $text): array
    {
        $parts = preg_split('/(?:\R[\h]*){2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);
        $fragments = [];

        foreach ($parts ?: [[$text, 0]] as [$part, $offset]) {
            array_push($fragments, ...$this->makeSizedFragments(
                $version,
                $text,
                $offset,
                $offset + strlen($part),
                array_merge($this->emptyState(), ['element_type' => 'text_block']),
            ));
        }

        return $fragments;
    }

    private function makeSizedFragments(SourceVersion $version, string $fullText, int $start, int $end, array $state): array
    {
        $slice = substr($fullText, $start, $end - $start);
        preg_match('/^\s*/u', $slice, $leading);
        preg_match('/\s*$/u', $slice, $trailing);
        $start += strlen($leading[0] ?? '');
        $end -= strlen($trailing[0] ?? '');

        if ($end <= $start) {
            return [];
        }

        $max = (int) config('legal_analysis.retrieval.max_fragment_chars', 5000);
        $ranges = [[$start, $end]];

        if (mb_strlen(substr($fullText, $start, $end - $start)) > $max) {
            $ranges = $this->lineRanges($fullText, $start, $end, $max);
        }

        $fragments = [];

        foreach ($ranges as [$rangeStart, $rangeEnd]) {
            $text = trim(substr($fullText, $rangeStart, $rangeEnd - $rangeStart));

            if (mb_strlen($text) < (int) config('legal_analysis.retrieval.min_fragment_chars', 30)) {
                continue;
            }

            $actualByte = strpos($fullText, $text, $rangeStart);
            $actualByte = $actualByte === false ? $rangeStart : $actualByte;
            $startOffset = mb_strlen(substr($fullText, 0, $actualByte));
            $endOffset = $startOffset + mb_strlen($text);
            $textHash = hash('sha256', $text);
            $idHash = substr(hash('sha256', implode('|', [
                $version->id, $version->hash, $startOffset, $endOffset, $textHash,
            ])), 0, 12);

            $fragments[] = new LegalContextFragment(
                fragmentId: 'sv'.$version->id.'-'.$idHash,
                sourceId: $version->source_id,
                sourceVersionId: $version->id,
                sourceTitle: $version->source->title,
                versionName: $version->version_name,
                article: $state['article'],
                paragraph: $state['paragraph'],
                subparagraph: $state['subparagraph'],
                startOffset: $startOffset,
                endOffset: $endOffset,
                textHash: $textHash,
                text: $text,
                section: $state['section'],
                chapter: $state['chapter'],
                part: $state['part'],
                appendix: $state['appendix'],
                elementType: $state['element_type'],
                elementLabel: $state['element_label'],
                textParagraph: $state['text_paragraph'],
            );
        }

        return $fragments;
    }

    private function lineRanges(string $text, int $start, int $end, int $max): array
    {
        $slice = substr($text, $start, $end - $start);
        $lines = preg_split('/(?<=\n)/u', $slice, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);
        $ranges = [];
        $groupStart = null;
        $groupEnd = null;
        $chars = 0;

        foreach ($lines ?: [[$slice, 0]] as [$line, $offset]) {
            $lineStart = $start + $offset;
            $lineEnd = $lineStart + strlen($line);
            $lineChars = mb_strlen($line);

            if ($groupStart !== null && $chars + $lineChars > $max) {
                $ranges[] = [$groupStart, $groupEnd];
                $groupStart = null;
                $chars = 0;
            }

            $groupStart ??= $lineStart;
            $groupEnd = $lineEnd;
            $chars += $lineChars;

            if ($lineChars > $max) {
                $ranges[] = [$groupStart, $groupEnd];
                $groupStart = null;
                $chars = 0;
            }
        }

        if ($groupStart !== null) {
            $ranges[] = [$groupStart, $groupEnd];
        }

        return $ranges;
    }

    private function emptyState(): array
    {
        return [
            'appendix' => null,
            'section' => null,
            'chapter' => null,
            'part' => null,
            'article' => null,
            'paragraph' => null,
            'subparagraph' => null,
            'text_paragraph' => null,
            'element_type' => 'text_block',
            'element_label' => null,
        ];
    }

    private function deduplicate(array $fragments): array
    {
        $unique = [];

        foreach ($fragments as $fragment) {
            $unique[$fragment->fragmentId] = $fragment;
        }

        return array_values($unique);
    }

    private function normalizeLocator(string $value): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}.-]+/u', '', $value) ?? $value);
    }
}
