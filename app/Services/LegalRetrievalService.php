<?php

namespace App\Services;

use App\Data\LegalContextFragment;
use App\Data\LegalRetrievalResult;
use App\Data\LegalStructuralContextPlan;
use App\Models\Analysis;
use App\Models\SourceVersion;
use Normalizer;

class LegalRetrievalService
{
    public function __construct(private readonly LegalStructureService $structureService) {}

    public function retrieve(
        Analysis $analysis,
        ?string $additionalQuery = null,
        array $requiredFragmentIds = [],
        ?LegalStructuralContextPlan $structuralPlan = null,
    ): LegalRetrievalResult {
        $analysis->loadMissing(['document', 'sourceVersions.source']);

        $query = trim($this->buildQuery($analysis)."\n".($additionalQuery ?? ''));
        $profile = $this->queryProfile($query);
        $allFragments = $this->allFragments($analysis);
        $corpus = $this->corpusStatistics($allFragments);
        $scored = [];
        $scores = [];
        $required = array_fill_keys($requiredFragmentIds, true);

        foreach ($allFragments as $fragment) {
            $score = $this->scoreFragment($fragment, $profile, $corpus);

            if (isset($required[$fragment->fragmentId])) {
                $score = max($score, (float) config('legal_analysis.discovery.required_fragment_score', 1000));
            }

            $scores[$fragment->fragmentId] = $score;

            if ($score > 0) {
                $scored[] = $fragment->withScore($score);
            }
        }

        usort($scored, fn (LegalContextFragment $a, LegalContextFragment $b) => $b->score <=> $a->score
            ?: $a->sourceVersionId <=> $b->sourceVersionId
            ?: $a->startOffset <=> $b->startOffset
            ?: strcmp($a->fragmentId, $b->fragmentId));

        $totalBudget = (int) config('legal_analysis.retrieval.context_budget_chars', 30000);
        $reservedBudget = (int) config('legal_analysis.retrieval.structural_reserved_chars', 18000);
        $configuredOptionalBudget = (int) config('legal_analysis.retrieval.optional_relevance_chars', 12000);
        $topK = (int) config('legal_analysis.retrieval.top_k', 30);
        $mandatoryGroups = $structuralPlan?->mandatoryGroups ?? [];
        $mandatoryIds = [];
        $mandatoryRoles = [];
        $mandatoryGroupAudit = [];
        $mandatoryChars = 0;

        foreach ($mandatoryGroups as $group) {
            $groupChars = 0;
            $groupIds = [];

            foreach ($group['fragments'] as $fragment) {
                $groupIds[] = $fragment->fragmentId;

                if (! isset($mandatoryIds[$fragment->fragmentId])) {
                    $mandatoryIds[$fragment->fragmentId] = $fragment;
                    $mandatoryRoles[$fragment->fragmentId] = $group['role'];
                    $groupChars += $this->promptChars($fragment);
                }
            }

            $mandatoryChars += $groupChars;
            $mandatoryGroupAudit[] = [
                'group_id' => $group['group_id'],
                'source_version_id' => $group['source_version_id'],
                'type' => $group['type'],
                'locator' => $group['locator'],
                'role' => $group['role'],
                'expected_fragment_ids' => $groupIds,
                'expected_chars' => $groupChars,
                'selected_fragment_ids' => [],
                'complete' => false,
            ];
        }

        $mandatoryFits = $mandatoryChars <= $totalBudget;
        $selectedMap = [];
        $mandatoryUsed = 0;

        if ($mandatoryFits) {
            foreach ($mandatoryIds as $fragmentId => $fragment) {
                $selectedMap[$fragmentId] = $fragment->withScore($scores[$fragmentId] ?? 0.0);
                $mandatoryUsed += $this->promptChars($fragment);
            }

            foreach ($mandatoryGroupAudit as &$groupAudit) {
                $groupAudit['selected_fragment_ids'] = $groupAudit['expected_fragment_ids'];
                $groupAudit['complete'] = true;
            }
            unset($groupAudit);
        }

        $planApplicable = $structuralPlan?->isApplicable() ?? false;
        $optionalLimit = $planApplicable
            ? min($configuredOptionalBudget, max(0, $totalBudget - $mandatoryUsed))
            : $totalBudget;
        $optionalUsed = 0;
        $optionalCount = 0;
        $ranks = [];
        $optionalDecisions = [];

        foreach ($scored as $index => $fragment) {
            $ranks[$fragment->fragmentId] = $index + 1;

            if (isset($mandatoryIds[$fragment->fragmentId])) {
                continue;
            }

            if ($optionalCount >= $topK) {
                $optionalDecisions[$fragment->fragmentId] = 'top_k';

                continue;
            }

            $chars = $this->promptChars($fragment);

            if ($optionalUsed + $chars > $optionalLimit) {
                $optionalDecisions[$fragment->fragmentId] = 'budget_skip';

                continue;
            }

            $selectedMap[$fragment->fragmentId] = $fragment;
            $optionalUsed += $chars;
            $optionalCount++;
            $optionalDecisions[$fragment->fragmentId] = 'selected_relevance';
        }

        $selected = array_values($selectedMap);
        usort($selected, fn (LegalContextFragment $a, LegalContextFragment $b) => $a->sourceVersionId <=> $b->sourceVersionId
            ?: $a->startOffset <=> $b->startOffset
            ?: strcmp($a->fragmentId, $b->fragmentId));

        $mandatoryComplete = $planApplicable
            && $mandatoryGroups !== []
            && $mandatoryFits
            && ($structuralPlan === null || $structuralPlan->sufficiency->status !== 'insufficient');
        $contextSufficiency = $structuralPlan?->sufficiency->withMandatorySelection(
            $mandatoryComplete,
            $mandatoryFits ? [] : ['mandatory_structural_context'],
            $mandatoryFits ? [] : ['mandatory_context_exceeds_total_budget'],
        );
        $fragmentAudit = [];

        foreach ($allFragments as $fragment) {
            $id = $fragment->fragmentId;
            $mandatory = isset($mandatoryIds[$id]);
            $selectedFragment = isset($selectedMap[$id]);
            $reason = $mandatory
                ? ($mandatoryFits ? $mandatoryRoles[$id] : 'mandatory_group_oversize')
                : (($scores[$id] ?? 0.0) <= 0
                    ? 'zero_relevance'
                    : ($optionalDecisions[$id] ?? 'budget_skip'));
            $fragmentAudit[] = [
                'fragment_id' => $id,
                'source_version_id' => $fragment->sourceVersionId,
                'article' => $fragment->article,
                'paragraph' => $fragment->paragraph,
                'subparagraph' => $fragment->subparagraph,
                'role' => $mandatory ? 'mandatory' : 'optional',
                'mandatory_reason' => $mandatory ? $mandatoryRoles[$id] : null,
                'score' => round($scores[$id] ?? 0.0, 6),
                'rank' => $ranks[$id] ?? null,
                'selected' => $selectedFragment,
                'reason' => $reason,
                'prompt_chars' => $this->promptChars($fragment),
            ];
        }

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
            retrievalAudit: [
                'groups' => $mandatoryGroupAudit,
                'fragments' => $fragmentAudit,
            ],
            budgetAudit: [
                'total_limit' => $totalBudget,
                'structural_reserved' => $reservedBudget,
                'optional_limit' => $optionalLimit,
                'mandatory_required' => $mandatoryChars,
                'mandatory_used' => $mandatoryUsed,
                'optional_used' => $optionalUsed,
                'total_used' => $mandatoryUsed + $optionalUsed,
                'mandatory_borrowed_from_optional' => max(0, $mandatoryUsed - $reservedBudget),
            ],
            contextSufficiency: $contextSufficiency,
        );
    }

    public function split(SourceVersion $version): array
    {
        return $this->structureService->fragments($version);
    }

    public function allFragments(Analysis $analysis): array
    {
        $analysis->loadMissing('sourceVersions.source');

        return $analysis->sourceVersions
            ->flatMap(fn (SourceVersion $version) => $this->split($version))
            ->values()
            ->all();
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
            fn (string $token) => mb_strlen($token) >= $minimum && ! isset($stopWords[$token]),
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

    private function scoreFragment(LegalContextFragment $fragment, array $profile, array $corpus): float
    {
        $weights = config('legal_analysis.retrieval.weights');
        $terms = $this->tokenize($fragment->text);
        $termCounts = array_count_values($terms);
        $matchedTerms = array_values(array_intersect($profile['terms'], array_keys($termCounts)));
        $score = 0.0;
        $k1 = (float) config('legal_analysis.retrieval.bm25.k1', 1.2);
        $b = (float) config('legal_analysis.retrieval.bm25.b', 0.75);
        $documentLength = max(1, count($terms));
        $averageLength = max(1.0, $corpus['average_length']);

        foreach ($matchedTerms as $term) {
            $frequency = $termCounts[$term];
            $documentFrequency = $corpus['document_frequency'][$term] ?? 0;
            $idf = log(1 + (($corpus['documents'] - $documentFrequency + 0.5) / ($documentFrequency + 0.5)));
            $normalization = $frequency + $k1 * (1 - $b + $b * ($documentLength / $averageLength));
            $score += $idf * (($frequency * ($k1 + 1)) / max(0.000001, $normalization));
        }

        if ($profile['terms'] !== []) {
            $score += (count($matchedTerms) / count($profile['terms'])) * ($weights['coverage'] ?? 0);
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

        return round($score, 6);
    }

    private function corpusStatistics(array $fragments): array
    {
        $documentFrequency = [];
        $totalLength = 0;

        foreach ($fragments as $fragment) {
            $terms = $this->tokenize($fragment->text);
            $totalLength += count($terms);

            foreach (array_unique($terms) as $term) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
        }

        $documents = max(1, count($fragments));

        return [
            'documents' => $documents,
            'document_frequency' => $documentFrequency,
            'average_length' => $totalLength / $documents,
        ];
    }

    private function promptChars(LegalContextFragment $fragment): int
    {
        return mb_strlen($fragment->toPromptBlock()) + 40;
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
        usort($fragments, fn (LegalContextFragment $a, LegalContextFragment $b) => $b->score <=> $a->score
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

        usort($selected, fn (LegalContextFragment $a, LegalContextFragment $b) => $a->sourceVersionId <=> $b->sourceVersionId
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
