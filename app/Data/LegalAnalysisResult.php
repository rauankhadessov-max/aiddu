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
    ) {
    }

    public function hasCompletelyInvalidCitations(): bool
    {
        return $this->returnedFindingsCount > 0 && $this->findings === [];
    }

    public function settings(): array
    {
        return array_merge($this->retrieval->toSnapshot(), [
            'prompt_version' => config('legal_analysis.prompt_version'),
            'validator_version' => $this->citationValidation->validatorVersion,
            'response_id' => $this->responseId,
            'usage' => $this->usage,
            'model' => $this->model,
            'request_payload_hash' => $this->requestPayloadHash,
            'prompt_hash' => $this->promptHash,
            'overall_assessment' => $this->overallAssessment,
            'citation_validation' => $this->citationValidation->toAudit(),
        ]);
    }
}
