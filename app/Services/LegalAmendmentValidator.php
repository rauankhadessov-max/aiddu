<?php

namespace App\Services;

use App\Data\AmendmentValidationResult;
use App\Data\LegalContextFragment;
use App\Data\LegalRetrievalResult;
use App\Models\Analysis;

class LegalAmendmentValidator
{
    public function __construct(
        private readonly LegalCitationValidator $citationValidator,
        private readonly LegalStructureService $structureService,
    ) {}

    public function validate(
        array $amendments,
        LegalRetrievalResult $context,
        Analysis $analysis,
        string $scenario,
    ): AmendmentValidationResult {
        $analysis->loadMissing(['document', 'sourceVersions.source']);
        $fragmentMap = $context->fragmentMap();
        $attachedVersions = $analysis->sourceVersions->keyBy('id');
        $accepted = [];
        $rejected = [];

        foreach ($amendments as $index => $amendment) {
            $target = is_array($amendment['target'] ?? null) ? $amendment['target'] : [];
            $mode = $target['target_mode'] ?? null;
            $reasons = [];
            [$citations, $citationReasons] = $this->citationValidator->validateCitations(
                is_array($amendment['citations'] ?? null) ? $amendment['citations'] : [],
                $context,
                $analysis,
            );
            $reasons = array_merge($reasons, $citationReasons);

            if ($citations === []) {
                $reasons[] = ['code' => 'missing_validated_citation'];
            }

            if (! collect($citations)->contains(fn (array $citation) => $citation['purpose'] === 'legal_basis')) {
                $reasons[] = ['code' => 'missing_legal_basis_citation'];
            }

            $ids = $mode === 'existing'
                ? ($target['target_fragment_ids'] ?? [])
                : ($target['anchor_fragment_ids'] ?? []);
            $ids = is_array($ids) ? array_values(array_unique($ids)) : [];
            $trustedFragments = [];

            foreach ($ids as $id) {
                if (! is_string($id) || ! isset($fragmentMap[$id])) {
                    $reasons[] = ['code' => 'unknown_target_fragment', 'fragment_id' => $id];

                    continue;
                }

                $trustedFragments[] = $fragmentMap[$id];
            }

            if ($trustedFragments === []) {
                $reasons[] = ['code' => $mode === 'new' ? 'missing_parent_anchor' : 'missing_existing_target'];
            }

            $sourceVersionIds = array_values(array_unique(array_map(
                fn (LegalContextFragment $fragment) => $fragment->sourceVersionId,
                $trustedFragments,
            )));

            if (count($sourceVersionIds) > 1) {
                $reasons[] = ['code' => 'target_spans_source_versions'];
            }

            $sourceVersionId = $sourceVersionIds[0] ?? null;
            $claimedVersionId = isset($target['source_version_id']) ? (int) $target['source_version_id'] : null;

            if ($sourceVersionId === null || ! isset($attachedVersions[$sourceVersionId])) {
                $reasons[] = ['code' => 'target_source_version_not_attached'];
            } elseif ($claimedVersionId !== $sourceVersionId) {
                $reasons[] = ['code' => 'target_source_version_mismatch'];
            }

            $first = $trustedFragments[0] ?? null;

            if ($mode === 'existing') {
                if ($first !== null && ! $this->hasTrustedLocator($first)) {
                    $reasons[] = ['code' => 'target_metadata_unavailable'];
                }

                if (! $this->sameStructuralElement($trustedFragments)) {
                    $reasons[] = ['code' => 'target_fragments_incompatible'];
                }

                if (! collect($citations)->contains(fn (array $citation) => $citation['purpose'] === 'target_current_text'
                    && in_array($citation['fragment_id'], $ids, true)
                )) {
                    $reasons[] = ['code' => 'missing_current_text_citation'];
                }
            } elseif ($mode === 'new') {
                if (! collect($citations)->contains(fn (array $citation) => $citation['purpose'] === 'parent_anchor'
                    && in_array($citation['fragment_id'], $ids, true)
                )) {
                    $reasons[] = ['code' => 'missing_parent_anchor_citation'];
                }

                if ($sourceVersionId !== null && isset($attachedVersions[$sourceVersionId])) {
                    $newLocator = $this->proposedLocator($target, $first);

                    if ($newLocator !== null) {
                        $allSourceFragments = $this->structureService->fragments($attachedVersions[$sourceVersionId]);

                        if ($this->structureService->locatorExists($allSourceFragments, $sourceVersionId, $newLocator)) {
                            $reasons[] = ['code' => 'proposed_locator_already_exists'];
                        }
                    }
                }
            } else {
                $reasons[] = ['code' => 'invalid_target_mode'];
            }

            $amendmentType = $amendment['amendment_type'] ?? null;
            $disposition = $amendment['disposition'] ?? null;
            $proposedText = is_string($amendment['proposed_text'] ?? null)
                ? trim($amendment['proposed_text'])
                : null;

            if (! in_array($amendmentType, ['new_edition', 'supplement_text', 'exclude_text', 'add_element', 'repeal_element'], true)) {
                $reasons[] = ['code' => 'invalid_amendment_type'];
            }

            if (! in_array($disposition, ['keep_as_proposed', 'revise', 'reject', 'draft'], true)) {
                $reasons[] = ['code' => 'invalid_disposition'];
            }

            if ($scenario === 'amendment_review' && $disposition === 'keep_as_proposed') {
                $proposedText = trim((string) $analysis->document->proposed_text);
            }

            if (! in_array($amendmentType, ['exclude_text', 'repeal_element'], true) && blank($proposedText)) {
                $reasons[] = ['code' => 'missing_proposed_text'];
            }

            if (! is_string($amendment['justification'] ?? null) || trim($amendment['justification']) === '') {
                $reasons[] = ['code' => 'missing_justification'];
            }

            if (! is_string($amendment['legal_basis'] ?? null) || trim($amendment['legal_basis']) === '') {
                $reasons[] = ['code' => 'missing_legal_basis'];
            }

            if ($reasons !== []) {
                $rejected[] = [
                    'index' => $index,
                    'reasons' => $reasons,
                    'amendment' => $amendment,
                ];

                continue;
            }

            usort($trustedFragments, fn (LegalContextFragment $a, LegalContextFragment $b) => $a->startOffset <=> $b->startOffset);
            $references = array_values(array_unique(array_map(
                fn (array $citation) => $fragmentMap[$citation['fragment_id']]->trustedReference(),
                $citations,
            )));
            $targetFragmentIds = $mode === 'existing' ? $ids : [];
            $anchorFragmentIds = $mode === 'new' ? $ids : [];
            $warnings = array_values(array_filter(
                is_array($amendment['warnings'] ?? null) ? $amendment['warnings'] : [],
                fn ($warning) => is_string($warning) && trim($warning) !== '',
            ));

            if ($mode === 'new' && blank($target['proposed_locator'] ?? null)) {
                $warnings[] = 'Нумерация нового структурного элемента требует юридико-технической проверки.';
            }

            $accepted[] = [
                '_model_index' => $index,
                'source_id' => $first->sourceId,
                'source_version_id' => $first->sourceVersionId,
                'target_mode' => $mode,
                'structural_element_type' => $target['structural_element_type'],
                'section' => $first->section,
                'chapter' => $first->chapter,
                'part' => $first->part,
                'article' => $first->article,
                'paragraph' => $first->paragraph,
                'subparagraph' => $first->subparagraph,
                'text_paragraph' => $first->textParagraph,
                'appendix' => $first->appendix,
                'proposed_locator' => $mode === 'new' ? ($target['proposed_locator'] ?? null) : null,
                'amendment_type' => $amendmentType,
                'disposition' => $disposition,
                'current_text' => $mode === 'existing'
                    ? implode("\n\n", array_map(fn (LegalContextFragment $fragment) => $fragment->text, $trustedFragments))
                    : null,
                'proposed_text' => $proposedText,
                'justification' => trim($amendment['justification']),
                'legal_basis' => trim($amendment['legal_basis']),
                'source_reference' => implode('; ', $references),
                'confidence_score' => (int) ($amendment['confidence_score'] ?? 0),
                'warnings' => array_values(array_unique($warnings)),
                'target_fragment_ids' => $targetFragmentIds,
                'anchor_fragment_ids' => $anchorFragmentIds,
                'citations' => $citations,
                'target_snapshot' => [
                    'source_id' => $first->sourceId,
                    'source_version_id' => $first->sourceVersionId,
                    'source_title' => $first->sourceTitle,
                    'version_name' => $first->versionName,
                    'fragment_ids' => $ids,
                    'fragment_hashes' => array_map(fn (LegalContextFragment $fragment) => $fragment->textHash, $trustedFragments),
                ],
            ];
        }

        return new AmendmentValidationResult(
            acceptedAmendments: $accepted,
            rejectedAmendments: $rejected,
            validatorVersion: config('legal_analysis.drafting_validator_version'),
        );
    }

    private function hasTrustedLocator(LegalContextFragment $fragment): bool
    {
        return $fragment->appendix !== null
            || $fragment->section !== null
            || $fragment->chapter !== null
            || $fragment->part !== null
            || $fragment->article !== null
            || $fragment->paragraph !== null
            || $fragment->subparagraph !== null
            || $fragment->textParagraph !== null;
    }

    private function sameStructuralElement(array $fragments): bool
    {
        if ($fragments === []) {
            return false;
        }

        $signatures = array_unique(array_map(fn (LegalContextFragment $fragment) => json_encode([
            $fragment->sourceVersionId,
            $fragment->appendix,
            $fragment->section,
            $fragment->chapter,
            $fragment->part,
            $fragment->article,
            $fragment->paragraph,
            $fragment->subparagraph,
            $fragment->textParagraph,
        ]), $fragments));

        return count($signatures) === 1;
    }

    private function proposedLocator(array $target, ?LegalContextFragment $anchor): ?array
    {
        $proposed = $target['proposed_locator'] ?? null;
        $type = $target['structural_element_type'] ?? null;

        if (! is_string($proposed) || trim($proposed) === '' || $anchor === null) {
            return null;
        }

        $locator = [
            'appendix' => $anchor->appendix,
            'section' => $anchor->section,
            'chapter' => $anchor->chapter,
            'part' => $anchor->part,
            'article' => $anchor->article,
            'paragraph' => $anchor->paragraph,
            'subparagraph' => $anchor->subparagraph,
            'text_paragraph' => $anchor->textParagraph,
        ];

        if (array_key_exists($type, $locator)) {
            $locator[$type] = trim($proposed);

            return $locator;
        }

        return null;
    }
}
