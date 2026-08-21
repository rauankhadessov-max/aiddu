<?php

namespace App\Services;

use App\Data\LegalContextFragment;
use App\Data\LegalRetrievalResult;
use App\Models\Analysis;
use App\Models\SourceVersion;
use Normalizer;

class LegalRetrievalService
{
    public function retrieve(Analysis $analysis): LegalRetrievalResult
    {
        $analysis->loadMissing(['document', 'sourceVersions.source']);

        $query = $this->buildQuery($analysis);
        $profile = $this->queryProfile($query);
        $scored = [];

        foreach ($analysis->sourceVersions as $version) {
            foreach ($this->split($version) as $fragment) {
                $score = $this->scoreFragment($fragment, $profile);

                if ($score > 0) {
                    $scored[] = $fragment->withScore($score);
                }
            }
        }

        $selected = $this->selectWithinBudget($scored);
        $selectedVersionIds = array_fill_keys(array_map(
            fn (LegalContextFragment $fragment) => $fragment->sourceVersionId,
            $selected,
        ), true);
        $snapshotFragments = array_map(
            fn (LegalContextFragment $fragment) => $fragment->toArray(),
            $selected,
        );
        $contextJson = json_encode(
            $snapshotFragments,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return new LegalRetrievalResult(
            fragments: $selected,
            sourceSnapshots: $analysis->sourceVersions
                ->filter(fn (SourceVersion $version) => isset($selectedVersionIds[$version->id]))
                ->map(fn (SourceVersion $version) => [
                    'source_id' => $version->source_id,
                    'source_version_id' => $version->id,
                    'source_title' => $version->source->title,
                    'version_name' => $version->version_name,
                    'effective_date' => $version->effective_date?->format('Y-m-d'),
                    'source_version_hash' => $version->hash,
                ])
                ->values()
                ->all(),
            queryHash: hash('sha256', $query),
            contextHash: hash('sha256', $contextJson),
            retrievalVersion: config('legal_analysis.retrieval_version'),
            totalChars: array_sum(array_map(
                fn (LegalContextFragment $fragment) => mb_strlen($fragment->toPromptBlock()),
                $selected,
            )),
        );
    }

    public function split(SourceVersion $version): array
    {
        $text = $version->text ?? '';

        if (trim($text) === '') {
            return [];
        }

        $articlePattern = '/^[\h]*(?:Статья|Бап)\h+(\d+(?:-\d+)*)(?:[.\h]|$)/imu';
        preg_match_all($articlePattern, $text, $matches, PREG_OFFSET_CAPTURE);

        if (($matches[0] ?? []) === []) {
            return $this->fallbackFragments($version, $text, 0, strlen($text));
        }

        $fragments = [];
        $firstArticleByte = $matches[0][0][1];

        if ($firstArticleByte > 0 && trim(substr($text, 0, $firstArticleByte)) !== '') {
            array_push(
                $fragments,
                ...$this->fallbackFragments($version, $text, 0, $firstArticleByte),
            );
        }

        foreach ($matches[0] as $index => $match) {
            $startByte = $match[1];
            $endByte = $matches[0][$index + 1][1] ?? strlen($text);
            $article = $matches[1][$index][0];

            array_push(
                $fragments,
                ...$this->articleFragments($version, $text, $startByte, $endByte, $article),
            );
        }

        return $fragments;
    }

    private function buildQuery(Analysis $analysis): string
    {
        $document = $analysis->document;

        return trim(implode("\n", array_filter([
            $analysis->instruction,
            $document?->title,
            $document?->content_text,
            $document?->current_text,
            $document?->proposed_text,
        ], fn ($value) => is_string($value) && trim($value) !== '')));
    }

    private function articleFragments(
        SourceVersion $version,
        string $fullText,
        int $startByte,
        int $endByte,
        string $article,
    ): array {
        $articleText = substr($fullText, $startByte, $endByte - $startByte);
        $pointPattern = '/^[\h]*(\d+(?:-\d+)*)\.\h+/mu';
        preg_match_all($pointPattern, $articleText, $points, PREG_OFFSET_CAPTURE);
        $requiredMatches = (int) config('legal_analysis.retrieval.reliable_locator_matches', 2);

        if (count($points[0] ?? []) < $requiredMatches) {
            return $this->sizedFragments(
                $version,
                $fullText,
                $startByte,
                $endByte,
                article: $article,
            );
        }

        $fragments = [];
        $firstPointByte = $startByte + $points[0][0][1];

        if ($firstPointByte > $startByte) {
            array_push($fragments, ...$this->sizedFragments(
                $version,
                $fullText,
                $startByte,
                $firstPointByte,
                article: $article,
            ));
        }

        foreach ($points[0] as $index => $pointMatch) {
            $pointStart = $startByte + $pointMatch[1];
            $pointEnd = isset($points[0][$index + 1])
                ? $startByte + $points[0][$index + 1][1]
                : $endByte;
            $paragraph = $points[1][$index][0];

            array_push(
                $fragments,
                ...$this->pointFragments(
                    $version,
                    $fullText,
                    $pointStart,
                    $pointEnd,
                    $article,
                    $paragraph,
                ),
            );
        }

        return $fragments;
    }

    private function pointFragments(
        SourceVersion $version,
        string $fullText,
        int $startByte,
        int $endByte,
        string $article,
        string $paragraph,
    ): array {
        $pointText = substr($fullText, $startByte, $endByte - $startByte);
        $subpointPattern = '/^[\h]*(\d+(?:-\d+)*)\)\h+/mu';
        preg_match_all($subpointPattern, $pointText, $subpoints, PREG_OFFSET_CAPTURE);
        $requiredMatches = (int) config('legal_analysis.retrieval.reliable_locator_matches', 2);

        if (count($subpoints[0] ?? []) < $requiredMatches) {
            return $this->sizedFragments(
                $version,
                $fullText,
                $startByte,
                $endByte,
                article: $article,
                paragraph: $paragraph,
            );
        }

        $fragments = [];
        $firstSubpointByte = $startByte + $subpoints[0][0][1];

        if ($firstSubpointByte > $startByte) {
            array_push($fragments, ...$this->sizedFragments(
                $version,
                $fullText,
                $startByte,
                $firstSubpointByte,
                article: $article,
                paragraph: $paragraph,
            ));
        }

        foreach ($subpoints[0] as $index => $subpointMatch) {
            $subpointStart = $startByte + $subpointMatch[1];
            $subpointEnd = isset($subpoints[0][$index + 1])
                ? $startByte + $subpoints[0][$index + 1][1]
                : $endByte;

            array_push($fragments, ...$this->sizedFragments(
                $version,
                $fullText,
                $subpointStart,
                $subpointEnd,
                article: $article,
                paragraph: $paragraph,
                subparagraph: $subpoints[1][$index][0],
            ));
        }

        return $fragments;
    }

    private function fallbackFragments(
        SourceVersion $version,
        string $fullText,
        int $startByte,
        int $endByte,
    ): array {
        $range = substr($fullText, $startByte, $endByte - $startByte);
        $parts = preg_split(
            '/(?:\R[\h]*){2,}/u',
            $range,
            -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE,
        );

        if (count($parts) <= 1) {
            return $this->sizedFragments($version, $fullText, $startByte, $endByte);
        }

        $fragments = [];

        foreach ($parts as [$part, $relativeByte]) {
            $partStart = $startByte + $relativeByte;
            $partEnd = $partStart + strlen($part);
            array_push(
                $fragments,
                ...$this->sizedFragments($version, $fullText, $partStart, $partEnd),
            );
        }

        return $fragments;
    }

    private function sizedFragments(
        SourceVersion $version,
        string $fullText,
        int $startByte,
        int $endByte,
        ?string $article = null,
        ?string $paragraph = null,
        ?string $subparagraph = null,
    ): array {
        [$trimmedStart, $trimmedEnd] = $this->trimByteRange($fullText, $startByte, $endByte);

        if ($trimmedEnd <= $trimmedStart) {
            return [];
        }

        $text = substr($fullText, $trimmedStart, $trimmedEnd - $trimmedStart);
        $maxChars = (int) config('legal_analysis.retrieval.max_fragment_chars', 5000);

        if (mb_strlen($text) <= $maxChars) {
            return $this->makeFragment(
                $version,
                $fullText,
                $trimmedStart,
                $trimmedEnd,
                $article,
                $paragraph,
                $subparagraph,
            );
        }

        $lines = preg_split('/(?<=\n)/u', $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);

        if (count($lines) <= 1) {
            return $this->makeFragment(
                $version,
                $fullText,
                $trimmedStart,
                $trimmedEnd,
                $article,
                $paragraph,
                $subparagraph,
            );
        }

        $groups = [];
        $groupStart = null;
        $groupEnd = null;
        $groupChars = 0;

        foreach ($lines as [$line, $relativeByte]) {
            $lineChars = mb_strlen($line);
            $lineStart = $trimmedStart + $relativeByte;
            $lineEnd = $lineStart + strlen($line);

            if ($groupStart !== null && $groupChars + $lineChars > $maxChars) {
                $groups[] = [$groupStart, $groupEnd];
                $groupStart = null;
                $groupChars = 0;
            }

            $groupStart ??= $lineStart;
            $groupEnd = $lineEnd;
            $groupChars += $lineChars;

            if ($lineChars > $maxChars) {
                $groups[] = [$groupStart, $groupEnd];
                $groupStart = null;
                $groupChars = 0;
            }
        }

        if ($groupStart !== null) {
            $groups[] = [$groupStart, $groupEnd];
        }

        $fragments = [];

        foreach ($groups as [$groupStart, $groupEnd]) {
            array_push($fragments, ...$this->makeFragment(
                $version,
                $fullText,
                $groupStart,
                $groupEnd,
                $article,
                $paragraph,
                $subparagraph,
            ));
        }

        return $fragments;
    }

    private function makeFragment(
        SourceVersion $version,
        string $fullText,
        int $startByte,
        int $endByte,
        ?string $article,
        ?string $paragraph,
        ?string $subparagraph,
    ): array {
        [$startByte, $endByte] = $this->trimByteRange($fullText, $startByte, $endByte);
        $text = substr($fullText, $startByte, $endByte - $startByte);
        $minChars = (int) config('legal_analysis.retrieval.min_fragment_chars', 30);

        if ($text === '' || mb_strlen($text) < $minChars) {
            return [];
        }

        $startOffset = mb_strlen(substr($fullText, 0, $startByte));
        $endOffset = $startOffset + mb_strlen($text);
        $textHash = hash('sha256', $text);
        $idHash = substr(hash('sha256', implode('|', [
            $version->id,
            $version->hash,
            $startOffset,
            $endOffset,
            $textHash,
        ])), 0, 12);

        return [new LegalContextFragment(
            fragmentId: 'sv'.$version->id.'-'.$idHash,
            sourceId: $version->source_id,
            sourceVersionId: $version->id,
            sourceTitle: $version->source->title,
            versionName: $version->version_name,
            article: $article,
            paragraph: $paragraph,
            subparagraph: $subparagraph,
            startOffset: $startOffset,
            endOffset: $endOffset,
            textHash: $textHash,
            text: $text,
        )];
    }

    private function trimByteRange(string $text, int $startByte, int $endByte): array
    {
        $slice = substr($text, $startByte, $endByte - $startByte);
        preg_match('/^\s*/u', $slice, $leading);
        preg_match('/\s*$/u', $slice, $trailing);

        $startByte += strlen($leading[0] ?? '');
        $endByte -= strlen($trailing[0] ?? '');

        return [$startByte, max($startByte, $endByte)];
    }

    private function queryProfile(string $query): array
    {
        preg_match_all('/[«"]([^»"]{5,160})[»"]/u', $query, $phraseMatches);

        return [
            'terms' => array_values(array_unique($this->tokenize($query))),
            'phrases' => array_slice(
                array_values(array_unique(array_map(
                    fn (string $phrase) => $this->normalize($phrase),
                    $phraseMatches[1] ?? [],
                ))),
                0,
                (int) config('legal_analysis.retrieval.max_exact_phrases', 20),
            ),
            'locators' => $this->extractLegalLocators($query),
        ];
    }

    private function tokenize(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', $this->normalize($text), $matches);
        $minimum = (int) config('legal_analysis.retrieval.min_term_length', 4);
        $stopWords = array_fill_keys(array_map(
            fn (string $word) => $this->normalize($word),
            array_merge(...array_values(config('legal_analysis.stop_words', []))),
        ), true);

        return array_values(array_filter(
            $matches[0] ?? [],
            fn (string $token) => mb_strlen($token) >= $minimum && !isset($stopWords[$token]),
        ));
    }

    private function extractLegalLocators(string $text): array
    {
        $patterns = [
            'article' => '/(?:стать(?:я|и|е|ю)|бап(?:тың|та|ты|қа)?)\s+([0-9]+(?:-[0-9]+)*)/iu',
            'paragraph' => '/(?:пункт(?:а|е|ом)?|тармақ(?:тың|та|ты|қа)?)\s+([0-9]+(?:-[0-9]+)*)/iu',
            'subparagraph' => '/(?:подпункт(?:а|е|ом)?|тармақша(?:ның|да|ны|ға)?)\s+([0-9]+(?:-[0-9]+)*)/iu',
        ];
        $locators = [];

        foreach ($patterns as $type => $pattern) {
            preg_match_all($pattern, $text, $matches);
            $locators[$type] = array_values(array_unique($matches[1] ?? []));
        }

        return $locators;
    }

    private function scoreFragment(LegalContextFragment $fragment, array $profile): float
    {
        $weights = config('legal_analysis.retrieval.weights');
        $frequencyLimit = (int) config('legal_analysis.retrieval.max_term_frequency', 3);
        $termCounts = array_count_values($this->tokenize($fragment->text));
        $matchedTerms = array_values(array_intersect($profile['terms'], array_keys($termCounts)));
        $score = 0.0;

        foreach ($matchedTerms as $term) {
            $score += min($frequencyLimit, $termCounts[$term]) * $weights['term_frequency'];
        }

        if ($profile['terms'] !== []) {
            $score += (count($matchedTerms) / count($profile['terms'])) * $weights['coverage'];
        }

        $normalizedFragment = $this->normalize($fragment->text);

        foreach ($profile['phrases'] as $phrase) {
            if ($phrase !== '' && $this->containsExactPhrase($normalizedFragment, $phrase)) {
                $score += $weights['exact_phrase'];
            }
        }

        foreach (['article', 'paragraph', 'subparagraph'] as $locator) {
            if ($fragment->{$locator} !== null && in_array($fragment->{$locator}, $profile['locators'][$locator], true)) {
                $score += $weights[$locator.'_reference'];
            }
        }

        if ($score <= 0) {
            return 0.0;
        }

        $baseLength = max(1, (int) config('legal_analysis.retrieval.length_normalization_chars', 1800));
        $excessRatio = max(0, mb_strlen($fragment->text) - $baseLength) / $baseLength;
        $divisor = 1 + ($excessRatio * $weights['length_penalty']);

        return round($score / $divisor, 6);
    }

    private function containsExactPhrase(string $text, string $phrase): bool
    {
        $parts = preg_split('/\s+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return false;
        }

        $pattern = implode('\\s+', array_map(
            static fn (string $part): string => preg_quote($part, '/'),
            $parts,
        ));

        return preg_match('/(?<![\p{L}\p{N}])'.$pattern.'(?![\p{L}\p{N}])/u', $text) === 1;
    }

    private function selectWithinBudget(array $fragments): array
    {
        usort($fragments, fn (LegalContextFragment $a, LegalContextFragment $b) =>
            $b->score <=> $a->score
                ?: $a->sourceVersionId <=> $b->sourceVersionId
                ?: $a->startOffset <=> $b->startOffset
                ?: strcmp($a->fragmentId, $b->fragmentId)
        );

        $budget = (int) config('legal_analysis.retrieval.context_budget_chars', 30000);
        $topK = (int) config('legal_analysis.retrieval.top_k', 30);
        $selected = [];
        $selectedIds = [];
        $usedChars = 0;
        $bestByVersion = [];

        foreach ($fragments as $fragment) {
            $bestByVersion[$fragment->sourceVersionId] ??= $fragment;
        }

        ksort($bestByVersion);

        foreach ($bestByVersion as $fragment) {
            $this->trySelect($fragment, $selected, $selectedIds, $usedChars, $budget, $topK);
        }

        foreach ($fragments as $fragment) {
            $this->trySelect($fragment, $selected, $selectedIds, $usedChars, $budget, $topK);
        }

        usort($selected, fn (LegalContextFragment $a, LegalContextFragment $b) =>
            $a->sourceVersionId <=> $b->sourceVersionId
                ?: $a->startOffset <=> $b->startOffset
                ?: strcmp($a->fragmentId, $b->fragmentId)
        );

        return $selected;
    }

    private function trySelect(
        LegalContextFragment $fragment,
        array &$selected,
        array &$selectedIds,
        int &$usedChars,
        int $budget,
        int $topK,
    ): void {
        if (isset($selectedIds[$fragment->fragmentId]) || count($selected) >= $topK) {
            return;
        }

        $fragmentChars = mb_strlen($fragment->toPromptBlock()) + 40;

        if ($usedChars + $fragmentChars > $budget) {
            return;
        }

        $selected[] = $fragment;
        $selectedIds[$fragment->fragmentId] = true;
        $usedChars += $fragmentChars;
    }

    private function normalize(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        $normalized = str_replace("\u{00A0}", ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized));
    }
}
