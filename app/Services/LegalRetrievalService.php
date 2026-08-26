<?php

namespace App\Services;

use App\Data\LegalContextFragment;
use App\Data\LegalRetrievalResult;
use App\Data\LegalStructuralContextPlan;
use App\Models\Analysis;
use App\Models\SourceVersion;

class LegalRetrievalService
{
    public function __construct(
        private readonly LegalStructureService $structureService,
        private readonly LegalRetrievalTextNormalizer $textNormalizer,
        private readonly LegalCrossSourceIssueService $crossSourceIssueService,
    ) {}

    public function retrieve(
        Analysis $analysis,
        ?string $additionalQuery = null,
        array $requiredFragmentIds = [],
        ?LegalStructuralContextPlan $structuralPlan = null,
        array $supportingCandidateIds = [],
        array $requestedLegalIssues = [],
        array $supportingQueries = [],
    ): LegalRetrievalResult {
        $analysis->loadMissing(['document', 'sourceVersions.source']);

        $query = trim(implode("\n", array_filter([
            $this->buildQuery($analysis),
            $additionalQuery,
            ...$requestedLegalIssues,
            ...$supportingQueries,
        ], fn ($value) => is_string($value) && trim($value) !== '')));
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

        [$supportingGroups, $supportingCoverage] = $this->supportingCandidateGroups(
            $supportingCandidateIds,
            $allFragments,
            $scores,
        );
        $crossSourceIssues = $this->crossSourceIssueService->discover(
            $analysis,
            [],
            [...$requestedLegalIssues, ...$supportingQueries],
        );
        [$crossSourceGroups, $crossSourceCoverage] = $this->crossSourceCandidates(
            $crossSourceIssues,
            $allFragments,
        );

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
        $configuredCrossSourceBudget = (int) config('legal_analysis.retrieval.cross_source_reserved_chars', 8000);
        $crossSourceLimit = min($configuredCrossSourceBudget, $optionalLimit);
        $crossSourceUsed = 0;
        $supportingUsed = 0;
        $crossSourceIds = [];
        $supportingIds = [];
        $selectedCrossGroupIds = [];
        $selectedSupportingGroupIds = [];

        foreach ($supportingGroups as $group) {
            $groupChars = array_sum(array_map(fn (LegalContextFragment $fragment) => $this->promptChars($fragment), $group['fragments']));

            if ($supportingUsed + $groupChars > $crossSourceLimit) {
                continue;
            }

            foreach ($group['fragments'] as $fragment) {
                if (isset($selectedMap[$fragment->fragmentId])) {
                    continue;
                }

                $selectedMap[$fragment->fragmentId] = $fragment->withScore(max(
                    $scores[$fragment->fragmentId] ?? 0.0,
                    $group['score'],
                ));
                $supportingIds[$fragment->fragmentId] = $group['candidate_fragment_ids'];
                $supportingUsed += $this->promptChars($fragment);
            }

            $selectedSupportingGroupIds[$group['group_id']] = true;
        }

        foreach ($crossSourceGroups as $group) {
            $groupChars = array_sum(array_map(fn (LegalContextFragment $fragment) => $this->promptChars($fragment), $group['fragments']));

            if ($supportingUsed + $crossSourceUsed + $groupChars > $crossSourceLimit) {
                continue;
            }

            foreach ($group['fragments'] as $fragment) {
                if (isset($selectedMap[$fragment->fragmentId])) {
                    continue;
                }

                $selectedMap[$fragment->fragmentId] = $fragment->withScore(max(
                    $scores[$fragment->fragmentId] ?? 0.0,
                    $group['score'],
                ));
                $crossSourceIds[$fragment->fragmentId] = $group['issue_ids'];
                $crossSourceUsed += $this->promptChars($fragment);
            }

            $selectedCrossGroupIds[$group['group_id']] = true;
        }

        $optionalUsed = 0;
        $optionalCount = 0;
        $ranks = [];
        $optionalDecisions = [];

        foreach ($scored as $index => $fragment) {
            $ranks[$fragment->fragmentId] = $index + 1;

            if (isset($mandatoryIds[$fragment->fragmentId])) {
                continue;
            }

            if (isset($crossSourceIds[$fragment->fragmentId])) {
                continue;
            }

            if ($optionalCount >= $topK) {
                $optionalDecisions[$fragment->fragmentId] = 'top_k';

                continue;
            }

            $chars = $this->promptChars($fragment);

            if ($supportingUsed + $crossSourceUsed + $optionalUsed + $chars > $optionalLimit) {
                $optionalDecisions[$fragment->fragmentId] = 'budget_skip';

                continue;
            }

            $selectedMap[$fragment->fragmentId] = $fragment;
            $optionalUsed += $chars;
            $optionalCount++;
            $optionalDecisions[$fragment->fragmentId] = 'selected_relevance';
        }

        $crossSourceCoverage = $this->finalizeCrossSourceCoverage(
            $crossSourceCoverage,
            $selectedCrossGroupIds,
            $selectedMap,
        );
        $supportingCoverage = $this->finalizeSupportingCoverage(
            $supportingCoverage,
            $selectedSupportingGroupIds,
            $selectedMap,
        );

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
            $supporting = isset($supportingIds[$id]);
            $crossSource = isset($crossSourceIds[$id]);
            $selectedFragment = isset($selectedMap[$id]);
            $reason = $mandatory
                ? ($mandatoryFits ? $mandatoryRoles[$id] : 'mandatory_group_oversize')
                : ($supporting
                    ? 'supporting_candidate'
                    : ($crossSource
                    ? 'cross_source_issue'
                    : (($scores[$id] ?? 0.0) <= 0
                    ? 'zero_relevance'
                    : ($optionalDecisions[$id] ?? 'budget_skip'))));
            $fragmentAudit[] = [
                'fragment_id' => $id,
                'source_version_id' => $fragment->sourceVersionId,
                'article' => $fragment->article,
                'paragraph' => $fragment->paragraph,
                'subparagraph' => $fragment->subparagraph,
                'role' => $mandatory ? 'mandatory' : ($supporting ? 'supporting' : ($crossSource ? 'cross_source' : 'optional')),
                'mandatory_reason' => $mandatory ? $mandatoryRoles[$id] : null,
                'supporting_candidate_ids' => $supportingIds[$id] ?? [],
                'cross_source_issue_ids' => $crossSourceIds[$id] ?? [],
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
                'supporting_candidates' => $supportingCoverage,
                'cross_source_coverage' => $crossSourceCoverage,
            ],
            budgetAudit: [
                'total_limit' => $totalBudget,
                'structural_reserved' => $reservedBudget,
                'optional_limit' => $optionalLimit,
                'mandatory_required' => $mandatoryChars,
                'mandatory_used' => $mandatoryUsed,
                'cross_source_reserved' => $crossSourceLimit,
                'cross_source_used' => $crossSourceUsed,
                'supporting_used' => $supportingUsed,
                'general_optional_used' => $optionalUsed,
                'optional_used' => $supportingUsed + $crossSourceUsed + $optionalUsed,
                'total_used' => $mandatoryUsed + $supportingUsed + $crossSourceUsed + $optionalUsed,
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
        return $this->textNormalizer->tokens($text);
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

        foreach ($profile['stem_phrases'] ?? [] as $phrase) {
            if ($phrase !== '' && $this->textNormalizer->containsStemPhrase($fragment->text, $phrase)) {
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

    private function supportingCandidateGroups(array $candidateIds, array $allFragments, array $scores): array
    {
        $candidateIds = array_values(array_unique(array_filter($candidateIds, 'is_string')));
        $fragmentsById = [];

        foreach ($allFragments as $fragment) {
            $fragmentsById[$fragment->fragmentId] = $fragment;
        }

        $structuralGroups = $this->structureService->structuralGroups($allFragments);
        $groups = [];
        $coverage = [];

        foreach ($candidateIds as $candidateId) {
            $candidate = $fragmentsById[$candidateId] ?? null;
            $coverageEntry = [
                'candidate_fragment_id' => $candidateId,
                'source_id' => $candidate?->sourceId,
                'source_version_id' => $candidate?->sourceVersionId,
                'group_id' => null,
                'group_type' => null,
                'fragment_ids' => [],
                'selected_fragment_ids' => [],
                'coverage_status' => $candidate === null ? 'unknown_fragment' : 'found_but_not_retrieved',
                'exclusion_reason' => $candidate === null ? 'unknown_fragment' : 'pending_budget_selection',
            ];

            if ($candidate === null) {
                $coverage[] = $coverageEntry;

                continue;
            }

            $group = $candidate->article !== null
                ? collect($structuralGroups)->first(fn (array $item) => $item['type'] === 'article'
                    && $item['source_version_id'] === $candidate->sourceVersionId
                    && $item['article'] === $candidate->article
                    && $item['appendix'] === $candidate->appendix
                    && $item['section'] === $candidate->section
                    && $item['chapter'] === $candidate->chapter
                    && $item['part'] === $candidate->part)
                : null;

            if (! is_array($group)) {
                $group = $this->boundedSupportingGroup($candidate, $allFragments);
            }

            $groupKey = $group['group_id'];
            $score = max(
                (float) ($scores[$candidateId] ?? 0.0),
                (float) config('legal_analysis.discovery.supporting_candidate_score', 500),
            );

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = array_merge($group, [
                    'score' => $score,
                    'candidate_fragment_ids' => [$candidateId],
                ]);
            } else {
                $groups[$groupKey]['score'] = max($groups[$groupKey]['score'], $score);
                $groups[$groupKey]['candidate_fragment_ids'][] = $candidateId;
                $groups[$groupKey]['candidate_fragment_ids'] = array_values(array_unique(
                    $groups[$groupKey]['candidate_fragment_ids'],
                ));
            }

            $coverageEntry['group_id'] = $groupKey;
            $coverageEntry['group_type'] = $group['type'];
            $coverageEntry['fragment_ids'] = array_map(
                fn (LegalContextFragment $fragment) => $fragment->fragmentId,
                $group['fragments'],
            );
            $coverage[] = $coverageEntry;
        }

        $groups = array_values($groups);
        usort($groups, fn (array $left, array $right) => $right['score'] <=> $left['score']
            ?: $left['source_version_id'] <=> $right['source_version_id']
            ?: strcmp($left['group_id'], $right['group_id']));

        return [$groups, $coverage];
    }

    private function boundedSupportingGroup(LegalContextFragment $candidate, array $allFragments): array
    {
        $stream = array_values(array_filter(
            $allFragments,
            fn (LegalContextFragment $fragment) => $fragment->sourceVersionId === $candidate->sourceVersionId
                && $fragment->appendix === $candidate->appendix,
        ));
        usort($stream, fn (LegalContextFragment $left, LegalContextFragment $right) => $left->startOffset <=> $right->startOffset);
        $candidateIndex = collect($stream)->search(
            fn (LegalContextFragment $fragment) => $fragment->fragmentId === $candidate->fragmentId,
        );
        $candidateIndex = $candidateIndex === false ? 0 : $candidateIndex;
        $radius = (int) config('legal_analysis.discovery.bounded_neighborhood_fragments', 1);
        $limit = (int) config('legal_analysis.discovery.bounded_group_chars', 8000);
        $indexes = [$candidateIndex];

        for ($distance = 1; $distance <= $radius; $distance++) {
            if (isset($stream[$candidateIndex - $distance])) {
                $indexes[] = $candidateIndex - $distance;
            }

            if (isset($stream[$candidateIndex + $distance])) {
                $indexes[] = $candidateIndex + $distance;
            }
        }

        usort($indexes, fn (int $left, int $right) => abs($left - $candidateIndex) <=> abs($right - $candidateIndex)
            ?: $left <=> $right);
        $selected = [];
        $used = 0;

        foreach ($indexes as $index) {
            $fragment = $stream[$index];
            $chars = $this->promptChars($fragment);

            if ($fragment->fragmentId !== $candidate->fragmentId && $used + $chars > $limit) {
                continue;
            }

            $selected[$fragment->fragmentId] = $fragment;
            $used += $chars;
        }

        $selected = array_values($selected);
        usort($selected, fn (LegalContextFragment $left, LegalContextFragment $right) => $left->startOffset <=> $right->startOffset);
        $key = implode('|', [
            'supporting',
            $candidate->sourceVersionId,
            $candidate->appendix,
            $candidate->paragraph,
            $candidate->fragmentId,
        ]);

        return [
            'group_id' => hash('sha256', $key),
            'source_id' => $candidate->sourceId,
            'source_version_id' => $candidate->sourceVersionId,
            'type' => $candidate->paragraph !== null ? 'paragraph_neighborhood' : 'fragment_neighborhood',
            'locator' => $candidate->paragraph ?? $candidate->fragmentId,
            'appendix' => $candidate->appendix,
            'article' => $candidate->article,
            'fragments' => $selected,
        ];
    }

    private function finalizeSupportingCoverage(array $coverage, array $selectedGroupIds, array $selectedMap): array
    {
        foreach ($coverage as &$entry) {
            if ($entry['coverage_status'] === 'unknown_fragment') {
                continue;
            }

            $selectedIds = array_values(array_filter(
                $entry['fragment_ids'],
                fn (string $id) => isset($selectedMap[$id]),
            ));
            $entry['selected_fragment_ids'] = $selectedIds;
            $entry['coverage_status'] = $selectedIds === [] ? 'found_but_not_retrieved' : 'retrieved';
            $entry['exclusion_reason'] = $selectedIds === [] ? 'supporting_budget_skip' : null;
            $entry['group_selected'] = isset($selectedGroupIds[$entry['group_id']]);
        }
        unset($entry);

        return $coverage;
    }

    private function crossSourceCandidates(array $issues, array $allFragments): array
    {
        $coverage = [];
        $candidateMap = [];
        $maximumGroups = (int) config('legal_analysis.retrieval.cross_source_max_groups_per_issue', 4);
        $minimumMatches = (int) config('legal_analysis.retrieval.cross_source_min_term_matches', 2);

        foreach ($issues as $issue) {
            $requestedSources = $issue['requested_sources'] ?? [];
            $coverageEntry = [
                'issue_id' => $issue['issue_id'],
                'legal_issue' => $issue['label'],
                'requested_sources' => $requestedSources,
                'corpus_matches' => [],
                'selected_fragment_ids' => [],
                'coverage_status' => $issue['status'] === 'ambiguous_source' ? 'ambiguous_source' : 'not_found_in_source_text',
                'exclusion_reason' => $issue['status'] === 'ambiguous_source' ? 'ambiguous_source' : 'no_relevant_structural_group',
            ];

            if ($issue['status'] === 'ambiguous_source') {
                $coverage[] = $coverageEntry;

                continue;
            }

            $versionId = (int) data_get($requestedSources, '0.source_version_id');
            $sourceFragments = array_values(array_filter(
                $allFragments,
                fn (LegalContextFragment $fragment) => $fragment->sourceVersionId === $versionId,
            ));
            $profile = $this->queryProfile((string) $issue['query']);
            $profile['locators'] = ['article' => [], 'paragraph' => [], 'subparagraph' => []];
            $profile['stem_phrases'] = $issue['phrases'] ?? [];
            $corpus = $this->corpusStatistics($sourceFragments);
            $fragmentScores = [];

            foreach ($sourceFragments as $fragment) {
                $matchedTerms = array_intersect(
                    $profile['terms'],
                    array_unique($this->tokenize($fragment->text)),
                );

                if (count($matchedTerms) < $minimumMatches) {
                    continue;
                }

                $score = $this->scoreFragment($fragment, $profile, $corpus);

                if ($score > 0) {
                    $fragmentScores[$fragment->fragmentId] = $score;
                }
            }

            $groups = [];

            foreach ($this->structureService->structuralGroups($sourceFragments) as $group) {
                $scores = array_values(array_filter(array_map(
                    fn (LegalContextFragment $fragment) => $fragmentScores[$fragment->fragmentId] ?? null,
                    $group['fragments'],
                ), fn ($score) => $score !== null));

                if ($scores === []) {
                    continue;
                }

                rsort($scores, SORT_NUMERIC);
                $groups[] = array_merge($group, [
                    'score' => round(array_sum(array_slice($scores, 0, 3)), 6),
                ]);
            }

            usort($groups, fn (array $a, array $b) => $b['score'] <=> $a['score']
                ?: $a['source_version_id'] <=> $b['source_version_id']
                ?: strcmp($a['group_id'], $b['group_id']));
            $groups = array_slice($groups, 0, $maximumGroups);

            foreach ($groups as $group) {
                $fragmentIds = array_map(fn (LegalContextFragment $fragment) => $fragment->fragmentId, $group['fragments']);
                $coverageEntry['corpus_matches'][] = [
                    'group_id' => $group['group_id'],
                    'source_id' => $group['source_id'],
                    'source_version_id' => $group['source_version_id'],
                    'article' => $group['article'],
                    'appendix' => $group['appendix'],
                    'score' => $group['score'],
                    'fragment_ids' => $fragmentIds,
                    'selected' => false,
                ];

                if (! isset($candidateMap[$group['group_id']])) {
                    $candidateMap[$group['group_id']] = array_merge($group, [
                        'issue_ids' => [$issue['issue_id']],
                    ]);
                } else {
                    $candidateMap[$group['group_id']]['score'] = max(
                        $candidateMap[$group['group_id']]['score'],
                        $group['score'],
                    );
                    $candidateMap[$group['group_id']]['issue_ids'][] = $issue['issue_id'];
                    $candidateMap[$group['group_id']]['issue_ids'] = array_values(array_unique(
                        $candidateMap[$group['group_id']]['issue_ids'],
                    ));
                }
            }

            if ($groups !== []) {
                $coverageEntry['coverage_status'] = 'found_but_not_retrieved';
                $coverageEntry['exclusion_reason'] = 'pending_budget_selection';
            }

            $coverage[] = $coverageEntry;
        }

        $candidates = array_values($candidateMap);
        usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']
            ?: $a['source_version_id'] <=> $b['source_version_id']
            ?: strcmp($a['group_id'], $b['group_id']));

        return [$candidates, $coverage];
    }

    private function finalizeCrossSourceCoverage(array $coverage, array $selectedGroupIds, array $selectedMap): array
    {
        foreach ($coverage as &$entry) {
            if ($entry['coverage_status'] === 'ambiguous_source' || $entry['corpus_matches'] === []) {
                continue;
            }

            $selectedIds = [];

            foreach ($entry['corpus_matches'] as &$match) {
                $match['selected'] = isset($selectedGroupIds[$match['group_id']])
                    || collect($match['fragment_ids'])->contains(fn (string $id) => isset($selectedMap[$id]));

                if ($match['selected']) {
                    array_push($selectedIds, ...array_values(array_filter(
                        $match['fragment_ids'],
                        fn (string $id) => isset($selectedMap[$id]),
                    )));
                }
            }
            unset($match);

            $entry['selected_fragment_ids'] = array_values(array_unique($selectedIds));
            $entry['coverage_status'] = $selectedIds === [] ? 'found_but_not_retrieved' : 'retrieved';
            $entry['exclusion_reason'] = $selectedIds === [] ? 'cross_source_budget_skip' : null;
        }
        unset($entry);

        return $coverage;
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
        return $this->textNormalizer->normalize($value);
    }
}
