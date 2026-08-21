<?php

namespace App\Services;

use App\Data\CitationValidationResult;
use App\Data\LegalContextFragment;
use App\Data\LegalRetrievalResult;
use App\Models\Analysis;
use Normalizer;

class LegalCitationValidator
{
    public function validate(
        array $findings,
        LegalRetrievalResult $context,
        Analysis $analysis,
    ): CitationValidationResult {
        $analysis->loadMissing('sourceVersions');

        $fragmentMap = $context->fragmentMap();
        $allowedSourceVersionIds = array_fill_keys(
            $analysis->sourceVersions->pluck('id')->map(fn ($id) => (int) $id)->all(),
            true,
        );
        $accepted = [];
        $rejected = [];

        foreach ($findings as $index => $finding) {
            $citations = $finding['citations'] ?? null;
            $reasons = [];
            $validatedCitations = [];

            if (!is_array($citations) || $citations === []) {
                $reasons[] = ['code' => 'missing_citation'];
            } else {
                foreach ($citations as $citationIndex => $citation) {
                    [$validatedCitation, $citationReasons] = $this->validateCitation(
                        is_array($citation) ? $citation : [],
                        $fragmentMap,
                        $allowedSourceVersionIds,
                    );

                    foreach ($citationReasons as $reason) {
                        $reasons[] = array_merge(['citation_index' => $citationIndex], $reason);
                    }

                    if ($validatedCitation !== null) {
                        $validatedCitations[] = $validatedCitation;
                    }
                }
            }

            if ($reasons !== []) {
                $rejected[] = [
                    'index' => $index,
                    'title' => $finding['title'] ?? null,
                    'reasons' => $reasons,
                    'finding' => $finding,
                ];

                continue;
            }

            $references = array_values(array_unique(array_map(
                fn (array $citation) => $fragmentMap[$citation['fragment_id']]->trustedReference(),
                $validatedCitations,
            )));

            $accepted[] = array_merge($finding, [
                '_model_index' => $index,
                'citations' => $validatedCitations,
                'source_reference' => implode('; ', $references),
            ]);
        }

        return new CitationValidationResult(
            acceptedFindings: $accepted,
            rejectedFindings: $rejected,
            validatorVersion: config('legal_analysis.citation_validator_version'),
        );
    }

    private function validateCitation(
        array $citation,
        array $fragmentMap,
        array $allowedSourceVersionIds,
    ): array {
        $fragmentId = $citation['fragment_id'] ?? null;

        if (!is_string($fragmentId) || !isset($fragmentMap[$fragmentId])) {
            return [null, [['code' => 'unknown_fragment', 'fragment_id' => $fragmentId]]];
        }

        /** @var LegalContextFragment $fragment */
        $fragment = $fragmentMap[$fragmentId];
        $reasons = [];

        if (!isset($allowedSourceVersionIds[$fragment->sourceVersionId])) {
            $reasons[] = [
                'code' => 'source_version_not_attached',
                'fragment_id' => $fragmentId,
                'source_version_id' => $fragment->sourceVersionId,
            ];
        }

        $quote = $citation['quote'] ?? null;

        if (!is_string($quote) || $this->normalizeText($quote) === '') {
            $reasons[] = ['code' => 'quote_not_found', 'fragment_id' => $fragmentId];
        } elseif (!str_contains($this->normalizeText($fragment->text), $this->normalizeText($quote))) {
            $reasons[] = ['code' => 'quote_not_found', 'fragment_id' => $fragmentId];
        }

        foreach (['article', 'paragraph', 'subparagraph'] as $locator) {
            $claimed = $citation[$locator] ?? null;
            $trusted = $fragment->{$locator};

            if ($claimed === null || $claimed === '' || $trusted === null) {
                continue;
            }

            if ($this->normalizeLocator((string) $claimed) !== $this->normalizeLocator($trusted)) {
                $reasons[] = [
                    'code' => $locator.'_mismatch',
                    'fragment_id' => $fragmentId,
                    'claimed' => (string) $claimed,
                    'trusted' => $trusted,
                ];
            }
        }

        if ($reasons !== []) {
            return [null, $reasons];
        }

        return [[
            'fragment_id' => $fragmentId,
            'quote' => trim((string) $quote),
            'article' => $citation['article'] ?? null,
            'paragraph' => $citation['paragraph'] ?? null,
            'subparagraph' => $citation['subparagraph'] ?? null,
            'source_id' => $fragment->sourceId,
            'source_version_id' => $fragment->sourceVersionId,
            'text_hash' => $fragment->textHash,
        ], []];
    }

    private function normalizeText(string $value): string
    {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC) ?: $value;
        $normalized = str_replace("\u{00A0}", ' ', $normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return mb_strtolower(trim($normalized));
    }

    private function normalizeLocator(string $value): string
    {
        $value = $this->normalizeText($value);
        $value = preg_replace(
            '/\b(?:статья|статьи|статье|пункт|пункта|пункте|подпункт|подпункта|подпункте|бап|тармақ|тармақша)\b/u',
            '',
            $value,
        ) ?? $value;

        return preg_replace('/[^\p{L}\p{N}-]+/u', '', $value) ?? $value;
    }
}
