<?php

namespace App\Data;

final readonly class LegalAnalysisResult
{
    public function __construct(
        public string $summary,
        public string $overallAssessment,
        public array $findings,
        public int $returnedFindingsCount,
        public LegalRetrievalResult $retrieval,
        public CitationValidationResult $citationValidation,
        public string $promptHash,
        public string $requestPayloadHash,
        public ?string $model,
        public ?string $responseId,
        public array $usage,
        public array $amendments = [],
        public int $returnedAmendmentsCount = 0,
        public string $sourceSufficiency = 'sufficient',
        public array $warnings = [],
        public ?AmendmentValidationResult $amendmentValidation = null,
        public ?array $discovery = null,
    ) {}

    public function hasCompletelyInvalidCitations(): bool
    {
        $returned = $this->returnedFindingsCount + $this->returnedAmendmentsCount;
        $accepted = count($this->findings) + count($this->amendments);

        return $returned > 0 && $accepted === 0;
    }

    public function settings(): array
    {
        $settings = array_merge($this->retrieval->toSnapshot(), [
            'prompt_version' => config('legal_analysis.prompt_version'),
            'validator_version' => $this->citationValidation->validatorVersion,
            'response_id' => $this->responseId,
            'usage' => $this->usage,
            'model' => $this->model,
            'request_payload_hash' => $this->requestPayloadHash,
            'prompt_hash' => $this->promptHash,
            'overall_assessment' => $this->overallAssessment,
            'citation_validation' => $this->citationValidation->toAudit(),
            'source_sufficiency' => $this->sourceSufficiency,
            'warnings' => $this->warnings,
        ]);

        if ($this->amendmentValidation !== null) {
            $settings['amendment_validation'] = $this->amendmentValidation->audit();
        }

        if ($this->discovery !== null) {
            $settings['discovery'] = $this->discovery;
        }

        return $settings;
    }
}
