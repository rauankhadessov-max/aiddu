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

        $currentArticles = $this->articleBlocks($current);
        $proposedArticles = $this->articleBlocks($proposed);

        foreach ($proposedArticles as $article => $proposedBlock) {
            $currentBlock = $currentArticles[$article] ?? null;
            $nestedIntents = $this->nestedDiffIntents($article, $currentBlock, $proposedBlock);

            if ($nestedIntents !== []) {
                array_push($intents, ...$nestedIntents);

                continue;
            }

            if ($currentBlock === null) {
                $intents[] = new LegalStructuralIntent(
                    elementType: 'article',
                    locator: $article,
                    targetMode: 'auto',
                    evidence: 'proposed_structural_heading',
                    article: $article,
                );

                continue;
            }

            if ($currentBlock['missing'] && ! $proposedBlock['missing']) {
                $intents[] = new LegalStructuralIntent(
                    elementType: 'article',
                    locator: $article,
                    targetMode: 'new',
                    evidence: 'per_target_explicit_absence',
                    article: $article,
                );

                continue;
            }

            if (! $currentBlock['missing'] && $this->comparableText($currentBlock['text']) !== $this->comparableText($proposedBlock['text'])) {
                $intents[] = new LegalStructuralIntent(
                    elementType: 'article',
                    locator: $article,
                    targetMode: 'existing',
                    evidence: 'structural_block_changed',
                    article: $article,
                );
            }
        }

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

        foreach ($intents as $intent) {
            $target = array_merge($intent->toArray(), [
                'status' => 'insufficient',
                'exists' => null,
                'source_version_id' => null,
                'group_id' => null,
                'parent_article' => $intent->elementType === 'article' ? null : $intent->article,
                'parent_paragraph' => $intent->elementType === 'subparagraph' ? $intent->paragraph : null,
                'predecessor' => null,
                'successor' => null,
                'missing_elements' => [],
                'ambiguities' => [],
                'reasons' => [],
            ]);

            if ($intent->article === null || ! $this->structureService->isSupportedNumericLocator($intent->article)) {
                $target['missing_elements'][] = 'supported_article_locator';
                $target['reasons'][] = 'unsupported_or_missing_parent_article';
                $targets[] = $target;

                continue;
            }

            $matching = array_values(array_filter(
                $groups,
                fn (array $group) => $group['type'] === 'article' && $group['article'] === $intent->article,
            ));

            if (count($matching) > 1) {
                $target['ambiguities'][] = 'article '.$intent->article.' matches multiple selected SourceVersions or scopes';
                $target['reasons'][] = 'ambiguous_target_source';
                $targets[] = $target;

                continue;
            }

            if ($intent->elementType !== 'article' && count($matching) === 1) {
                $group = $matching[0];
                $nestedExists = $this->nestedLocatorExists($group, $intent);
                $mode = $intent->targetMode === 'new'
                    ? 'new'
                    : ($intent->targetMode === 'existing' ? 'existing' : ($nestedExists ? 'existing' : 'new'));

                if (($mode === 'new' && $nestedExists) || ($mode === 'existing' && ! $nestedExists)) {
                    $target['exists'] = $nestedExists;
                    $target['source_version_id'] = $group['source_version_id'];
                    $target['group_id'] = $group['group_id'];
                    $target['missing_elements'][] = $intent->elementType.' '.$intent->locator;
                    $target['reasons'][] = $mode === 'new' ? 'new_target_already_exists' : 'existing_target_not_found';
                    $targets[] = $target;

                    continue;
                }

                $group['role'] = 'mandatory_parent';
                $mandatory[$group['group_id']] = $group;
                $modes[] = $mode;
                $anchors = $mode === 'new' ? $this->nestedAnchorLocators($group, $intent) : [];
                $targets[] = array_merge($target, [
                    'status' => 'resolved',
                    'target_mode' => $mode,
                    'exists' => $nestedExists,
                    'source_version_id' => $group['source_version_id'],
                    'group_id' => $group['group_id'],
                    'predecessor' => $anchors['predecessor'] ?? null,
                    'successor' => $anchors['successor'] ?? null,
                ]);

                continue;
            }

            if ($intent->elementType !== 'article') {
                $target['missing_elements'][] = 'parent article '.$intent->article;
                $target['reasons'][] = 'parent_article_not_found';
                $targets[] = $target;

                continue;
            }

            if (count($matching) === 1 && $intent->targetMode !== 'new') {
                $group = $matching[0];
                $group['role'] = 'mandatory_target';
                $mandatory[$group['group_id']] = $group;
                $modes[] = 'existing';
                $targets[] = array_merge($target, [
                    'status' => 'resolved',
                    'target_mode' => 'existing',
                    'exists' => true,
                    'source_version_id' => $group['source_version_id'],
                    'group_id' => $group['group_id'],
                ]);

                continue;
            }

            if (count($matching) === 1) {
                $group = $matching[0];
                $target['exists'] = true;
                $target['source_version_id'] = $group['source_version_id'];
                $target['group_id'] = $group['group_id'];
                $target['missing_elements'][] = 'new article '.$intent->article;
                $target['reasons'][] = 'new_target_already_exists';
                $targets[] = $target;

                continue;
            }

            $anchorCandidates = $this->anchorCandidates($groups, $intent->article);

            if (count($anchorCandidates) !== 1) {
                if ($anchorCandidates === []) {
                    $target['missing_elements'][] = 'structural anchors for article '.$intent->article;
                    $target['reasons'][] = 'structural_anchor_not_found';
                } else {
                    $target['ambiguities'][] = 'anchors for article '.$intent->article.' match multiple structural scopes';
                    $target['reasons'][] = 'ambiguous_structural_scope';
                }
                $targets[] = $target;

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
            $targets[] = array_merge($target, [
                'status' => 'resolved',
                'target_mode' => 'new',
                'exists' => false,
                'source_version_id' => $candidate['source_version_id'],
                'predecessor' => $candidate['predecessor']['article'] ?? null,
                'successor' => $candidate['successor']['article'] ?? null,
            ]);
        }

        foreach ($targets as $target) {
            array_push($missing, ...$target['missing_elements']);
            array_push($ambiguities, ...$target['ambiguities']);
            array_push($reasons, ...$target['reasons']);
        }

        $resolved = $targets !== []
            && collect($targets)->every(fn (array $target) => $target['status'] === 'resolved')
            && $mandatory !== [];
        $targetMode = count(array_unique($modes)) === 1 ? ($modes[0] ?? null) : 'mixed';

        return new LegalStructuralContextPlan(
            mandatoryGroups: array_values($mandatory),
            sufficiency: new ContextSufficiencyResult(
                status: $resolved ? 'sufficient' : 'insufficient',
                applicable: true,
                targetMode: $targetMode,
                targetResolved: $resolved,
                targetFound: $resolved && collect($targets)->every(fn (array $target) => $target['exists'] === true),
                parentFound: collect($targets)->every(fn (array $target) => $target['element_type'] === 'article' || $target['group_id'] !== null),
                anchorsFound: collect($targets)->every(fn (array $target) => $target['target_mode'] !== 'new' || $target['element_type'] !== 'article' || ($target['predecessor'] !== null && $target['successor'] !== null)),
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

    private function articleBlocks(string $text): array
    {
        preg_match_all(
            '/^[\h]*(?:Статья|СТАТЬЯ|Бап)\h+([0-9]+(?:[-.]\d+)*)\b[^\r\n]*/imu',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        $blocks = [];

        foreach ($matches[0] ?? [] as $index => $heading) {
            $start = $heading[1];
            $end = $matches[0][$index + 1][1] ?? strlen($text);
            $locator = str_replace('.', '-', (string) $matches[1][$index][0]);
            $blockText = substr($text, $start, $end - $start);
            $blocks[$locator] = [
                'locator' => $locator,
                'text' => $blockText,
                'missing' => $this->isExplicitlyMissing($blockText),
                'elements' => $this->nestedElements($blockText),
            ];
        }

        return $blocks;
    }

    private function nestedElements(string $articleText): array
    {
        preg_match_all(
            '/^[\h]*(?:(?<paragraph>[0-9]+(?:-[0-9]+)*)\.|(?<subparagraph>[0-9]+(?:-[0-9]+)*)\))\h+[^\r\n]*/mu',
            $articleText,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        $elements = [];
        $paragraph = null;

        foreach ($matches as $index => $match) {
            $isParagraph = ($match['paragraph'][1] ?? -1) >= 0 && ($match['paragraph'][0] ?? '') !== '';
            $type = $isParagraph ? 'paragraph' : 'subparagraph';
            $locator = (string) ($isParagraph ? $match['paragraph'][0] : $match['subparagraph'][0]);
            $start = $match[0][1];
            $end = $matches[$index + 1][0][1] ?? strlen($articleText);

            if ($isParagraph) {
                $paragraph = $locator;
            }

            $elementText = substr($articleText, $start, $end - $start);
            $key = implode('|', [$type, $isParagraph ? $locator : $paragraph, $type === 'subparagraph' ? $locator : null]);
            $elements[$key] = [
                'type' => $type,
                'locator' => $locator,
                'paragraph' => $isParagraph ? $locator : $paragraph,
                'subparagraph' => $type === 'subparagraph' ? $locator : null,
                'text' => $elementText,
                'missing' => $this->isExplicitlyMissing($elementText),
            ];
        }

        return $elements;
    }

    private function nestedDiffIntents(string $article, ?array $currentBlock, array $proposedBlock): array
    {
        if ($currentBlock === null || $currentBlock['missing']) {
            return [];
        }

        $intents = [];

        foreach ($proposedBlock['elements'] as $key => $proposedElement) {
            $currentElement = $currentBlock['elements'][$key] ?? null;

            if ($currentElement !== null
                && ! $currentElement['missing']
                && $this->comparableText($currentElement['text']) === $this->comparableText($proposedElement['text'])) {
                continue;
            }

            $mode = $currentElement === null
                ? 'auto'
                : (($currentElement['missing'] && ! $proposedElement['missing']) ? 'new' : 'existing');
            $intents[] = new LegalStructuralIntent(
                elementType: $proposedElement['type'],
                locator: $proposedElement['locator'],
                targetMode: $mode,
                evidence: $mode === 'new' ? 'per_target_explicit_absence' : 'nested_structural_diff',
                article: $article,
                paragraph: $proposedElement['paragraph'],
                subparagraph: $proposedElement['subparagraph'],
            );
        }

        return $intents;
    }

    private function nestedLocatorExists(array $group, LegalStructuralIntent $intent): bool
    {
        foreach ($group['fragments'] as $fragment) {
            if ($intent->paragraph !== null && (string) $fragment->paragraph !== $intent->paragraph) {
                continue;
            }

            if ($intent->subparagraph !== null && (string) $fragment->subparagraph !== $intent->subparagraph) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function nestedAnchorLocators(array $group, LegalStructuralIntent $intent): array
    {
        $locators = [];

        foreach ($group['fragments'] as $fragment) {
            if ($intent->elementType === 'subparagraph' && (string) $fragment->paragraph !== (string) $intent->paragraph) {
                continue;
            }

            $locator = $intent->elementType === 'subparagraph' ? $fragment->subparagraph : $fragment->paragraph;

            if ($locator !== null && $this->structureService->isSupportedNumericLocator((string) $locator)) {
                $locators[(string) $locator] = true;
            }
        }

        $locators = array_keys($locators);
        usort($locators, fn (string $left, string $right) => $this->structureService->compareNumericLocators($left, $right));
        $predecessor = $successor = null;

        foreach ($locators as $locator) {
            $comparison = $this->structureService->compareNumericLocators($locator, $intent->locator);

            if ($comparison < 0) {
                $predecessor = $locator;
            } elseif ($comparison > 0) {
                $successor = $locator;
                break;
            }
        }

        return [
            'predecessor' => $predecessor === null ? null : (string) $predecessor,
            'successor' => $successor === null ? null : (string) $successor,
        ];
    }

    private function isExplicitlyMissing(string $text): bool
    {
        $withoutLocator = preg_replace(
            '/^[\h]*(?:(?:статья|бап)\h+[0-9]+(?:[-.]\d+)*[^\r\n]*?\.|[0-9]+(?:-[0-9]+)*[.)])\h*/iu',
            '',
            trim($text),
        ) ?? $text;
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $withoutLocator) ?? $withoutLocator));

        if ($normalized === '') {
            return true;
        }

        $withoutAbsence = preg_replace('/\b(?:отсутствует|жоқ)\b/iu', '', $normalized) ?? $normalized;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', '', $withoutAbsence) ?? $withoutAbsence) === '';
    }

    private function comparableText(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
