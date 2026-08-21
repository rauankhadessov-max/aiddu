<?php

namespace App\Services;

use App\Data\ContextSufficiencyResult;
use App\Data\LegalStructuralContextPlan;
use App\Data\LegalStructuralIntent;
use App\Models\Analysis;

class LegalStructuralDiscoveryService
{
    public function __construct(
        private readonly LegalRetrievalService $retrievalService,
        private readonly LegalStructureService $structureService,
    ) {}

    public function plan(Analysis $analysis, array $candidateFragmentIds = []): LegalStructuralContextPlan
    {
        $analysis->loadMissing(['document', 'sourceVersions.source']);
        $fragments = $this->retrievalService->allFragments($analysis);
        $groups = $this->structureService->structuralGroups($fragments);

        if ($candidateFragmentIds !== []) {
            return $this->resolveCandidateGroups($groups, $candidateFragmentIds);
        }

        $intents = $this->detectIntents($analysis);

        if ($intents === []) {
            return new LegalStructuralContextPlan(
                [],
                new ContextSufficiencyResult,
            );
        }

        return $this->resolveIntents($analysis, $groups, $intents);
    }

    public function planFromCandidates(Analysis $analysis, array $candidateFragmentIds): LegalStructuralContextPlan
    {
        $analysis->loadMissing(['document', 'sourceVersions.source']);
        $groups = $this->structureService->structuralGroups(
            $this->retrievalService->allFragments($analysis),
        );

        return $this->resolveCandidateGroups($groups, $candidateFragmentIds);
    }

    public function detectIntents(Analysis $analysis): array
    {
        $document = $analysis->document;
        $proposed = (string) ($document?->proposed_text ?? '');
        $current = (string) ($document?->current_text ?? '');
        $intents = [];

        preg_match_all(
            '/(?:пункт|тармақ)\s+([0-9]+(?:-[0-9]+)*)\s+(?:статьи|баптың)\s+([0-9]+(?:-[0-9]+)*)[^\r\n]{0,100}\b(?:изложить|дополнить|исключить|заменить)\b/iu',
            $proposed."\n".$current,
            $pointOperations,
            PREG_SET_ORDER,
        );

        foreach ($pointOperations as $match) {
            $intents[] = new LegalStructuralIntent(
                elementType: 'paragraph',
                locator: $match[1],
                targetMode: 'existing',
                evidence: 'operative_amendment_text',
                article: $match[2],
                paragraph: $match[1],
            );
        }

        preg_match_all(
            '/(?:статью|бапты)\s+([0-9]+(?:-[0-9]+)*)[^\r\n]{0,100}\b(?:изложить|дополнить|исключить|заменить)\b/iu',
            $proposed."\n".$current,
            $articleOperations,
            PREG_SET_ORDER,
        );

        foreach ($articleOperations as $match) {
            $intents[] = new LegalStructuralIntent(
                elementType: 'article',
                locator: $match[1],
                targetMode: 'existing',
                evidence: 'operative_amendment_text',
                article: $match[1],
            );
        }

        preg_match_all(
            '/^[\h]*(?:Статья|СТАТЬЯ|Бап)\h+([0-9]+(?:[-.]\d+)*)\b/imu',
            $proposed,
            $proposedHeadings,
            PREG_SET_ORDER,
        );

        foreach ($proposedHeadings as $match) {
            $intents[] = new LegalStructuralIntent(
                elementType: 'article',
                locator: str_replace('.', '-', $match[1]),
                targetMode: 'auto',
                evidence: 'proposed_structural_heading',
                article: str_replace('.', '-', $match[1]),
            );
        }

        preg_match_all(
            '/^[\h]*(?:Статья|СТАТЬЯ|Бап)\h+([0-9]+(?:[-.]\d+)*)\b/imu',
            $current,
            $currentHeadings,
            PREG_SET_ORDER,
        );

        foreach ($currentHeadings as $match) {
            $locator = str_replace('.', '-', $match[1]);

            if (! collect($intents)->contains(fn (LegalStructuralIntent $intent) => $intent->article === $locator)) {
                $intents[] = new LegalStructuralIntent(
                    elementType: 'article',
                    locator: $locator,
                    targetMode: 'existing',
                    evidence: 'current_structural_heading',
                    article: $locator,
                );
            }
        }

        $unique = [];

        foreach ($intents as $intent) {
            $key = implode('|', [$intent->elementType, $intent->article, $intent->paragraph, $intent->subparagraph]);
            $unique[$key] ??= $intent;
        }

        return array_values($unique);
    }

    private function resolveIntents(Analysis $analysis, array $groups, array $intents): LegalStructuralContextPlan
    {
        $mandatory = [];
        $targets = [];
        $missing = [];
        $ambiguities = [];
        $reasons = [];
        $modes = [];
        $currentMissing = $this->currentTextIsMissing((string) ($analysis->document?->current_text ?? ''));

        foreach ($intents as $intent) {
            if ($intent->article === null || ! $this->structureService->isSupportedNumericLocator($intent->article)) {
                $missing[] = 'supported_article_locator';
                $reasons[] = 'unsupported_or_missing_parent_article';

                continue;
            }

            $matching = array_values(array_filter(
                $groups,
                fn (array $group) => $group['type'] === 'article' && $group['article'] === $intent->article,
            ));

            if (count($matching) === 1) {
                $group = $matching[0];
                $group['role'] = $intent->elementType === 'article' ? 'mandatory_target' : 'mandatory_parent';
                $mandatory[$group['group_id']] = $group;
                $modes[] = 'existing';
                $targets[] = array_merge($intent->toArray(), [
                    'target_mode' => 'existing',
                    'exists' => true,
                    'source_version_id' => $group['source_version_id'],
                    'group_id' => $group['group_id'],
                ]);

                continue;
            }

            if (count($matching) > 1) {
                $ambiguities[] = 'article '.$intent->article.' matches multiple selected SourceVersions or scopes';
                $reasons[] = 'ambiguous_target_source';

                continue;
            }

            $mayBeNew = $intent->targetMode === 'auto'
                && $intent->evidence === 'proposed_structural_heading'
                && $currentMissing;

            if (! $mayBeNew) {
                $missing[] = 'article '.$intent->article;
                $reasons[] = 'existing_target_not_found';

                continue;
            }

            $anchorCandidates = $this->anchorCandidates($groups, $intent->article);

            if (count($anchorCandidates) !== 1) {
                if ($anchorCandidates === []) {
                    $missing[] = 'structural anchors for article '.$intent->article;
                    $reasons[] = 'structural_anchor_not_found';
                } else {
                    $ambiguities[] = 'anchors for article '.$intent->article.' match multiple structural scopes';
                    $reasons[] = 'ambiguous_structural_scope';
                }

                continue;
            }

            $candidate = $anchorCandidates[0];

            foreach (['predecessor', 'successor'] as $anchorRole) {
                if ($candidate[$anchorRole] === null) {
                    continue;
                }

                $group = $candidate[$anchorRole];
                $group['role'] = 'anchor_'.$anchorRole;
                $mandatory[$group['group_id']] = $group;
            }

            $modes[] = 'new';
            $targets[] = array_merge($intent->toArray(), [
                'target_mode' => 'new',
                'exists' => false,
                'source_version_id' => $candidate['source_version_id'],
                'predecessor' => $candidate['predecessor']['article'] ?? null,
                'successor' => $candidate['successor']['article'] ?? null,
            ]);
        }

        $resolved = $targets !== [] && $missing === [] && $ambiguities === [] && $mandatory !== [];
        $targetMode = count(array_unique($modes)) === 1 ? ($modes[0] ?? null) : 'mixed';

        return new LegalStructuralContextPlan(
            mandatoryGroups: array_values($mandatory),
            sufficiency: new ContextSufficiencyResult(
                status: $resolved ? 'sufficient' : 'insufficient',
                applicable: true,
                targetMode: $targetMode,
                targetResolved: $resolved,
                targetFound: $resolved && ! in_array('new', $modes, true),
                parentFound: $resolved,
                anchorsFound: $resolved,
                mandatoryContextComplete: false,
                targets: $targets,
                missingElements: array_values(array_unique($missing)),
                ambiguities: array_values(array_unique($ambiguities)),
                reasons: array_values(array_unique($reasons)),
            ),
            intents: $intents,
        );
    }

    private function resolveCandidateGroups(array $groups, array $candidateFragmentIds): LegalStructuralContextPlan
    {
        $candidateMap = array_fill_keys(array_values(array_unique($candidateFragmentIds)), true);
        $mandatory = [];
        $matchedIds = [];

        foreach ($groups as $group) {
            foreach ($group['fragments'] as $fragment) {
                if (! isset($candidateMap[$fragment->fragmentId])) {
                    continue;
                }

                $group['role'] = $group['type'] === 'article' ? 'mandatory_target' : 'mandatory_parent';
                $mandatory[$group['group_id']] = $group;
                $matchedIds[] = $fragment->fragmentId;
            }
        }

        $missing = array_values(array_diff(array_keys($candidateMap), $matchedIds));

        if ($candidateFragmentIds === []) {
            $missing[] = 'discovery_candidate';
        }
        $resolved = $mandatory !== [] && $missing === [];

        return new LegalStructuralContextPlan(
            mandatoryGroups: array_values($mandatory),
            sufficiency: new ContextSufficiencyResult(
                status: $resolved ? 'sufficient' : 'insufficient',
                applicable: true,
                targetMode: 'existing',
                targetResolved: $resolved,
                targetFound: $resolved,
                parentFound: $resolved,
                anchorsFound: true,
                mandatoryContextComplete: false,
                targets: array_map(fn (array $group) => [
                    'target_mode' => 'existing',
                    'exists' => true,
                    'source_version_id' => $group['source_version_id'],
                    'group_id' => $group['group_id'],
                    'type' => $group['type'],
                    'locator' => $group['locator'],
                ], array_values($mandatory)),
                missingElements: $missing,
                reasons: $resolved ? [] : ['discovery_candidate_not_found'],
            ),
        );
    }

    private function anchorCandidates(array $groups, string $locator): array
    {
        $scopes = [];

        foreach ($groups as $group) {
            if ($group['type'] !== 'article' || ! $this->structureService->isSupportedNumericLocator($group['locator'])) {
                continue;
            }

            $scopes[$group['scope_key']][] = $group;
        }

        $candidates = [];

        foreach ($scopes as $scopeGroups) {
            usort($scopeGroups, fn (array $a, array $b) => $this->structureService->compareNumericLocators($a['locator'], $b['locator']));
            $predecessor = null;
            $successor = null;

            foreach ($scopeGroups as $group) {
                $comparison = $this->structureService->compareNumericLocators($group['locator'], $locator);

                if ($comparison < 0) {
                    $predecessor = $group;
                } elseif ($comparison > 0) {
                    $successor = $group;
                    break;
                }
            }

            if ($predecessor === null || $successor === null) {
                continue;
            }

            $candidates[] = [
                'source_version_id' => $predecessor['source_version_id'],
                'predecessor' => $predecessor,
                'successor' => $successor,
            ];
        }

        return $candidates;
    }

    private function currentTextIsMissing(string $text): bool
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));

        if ($normalized === '') {
            return true;
        }

        $withoutLocators = preg_replace(
            '/(?:статья|бап)\s+[0-9]+(?:[-.]\d+)*/iu',
            '',
            $normalized,
        ) ?? $normalized;
        $withoutAbsence = preg_replace('/\b(?:отсутствует|жоқ)\b/iu', '', $withoutLocators) ?? $withoutLocators;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $withoutAbsence) ?? $withoutAbsence) === '';
    }
}
