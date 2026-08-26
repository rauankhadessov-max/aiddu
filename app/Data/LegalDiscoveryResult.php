<?php

namespace App\Data;

final readonly class LegalDiscoveryResult
{
    public function __construct(
        public LegalRetrievalResult $retrieval,
        public string $sourceSufficiency,
        public array $warnings,
        public array $candidateFragmentIds,
        public array $searchQueries,
        public ?string $responseId,
        public array $usage,
        public ?string $model,
        public ?string $requestPayloadHash,
        public ?array $contextSufficiency = null,
        public array $targetCandidateFragmentIds = [],
        public array $supportingCandidateFragmentIds = [],
        public array $requestedLegalIssues = [],
    ) {}

    public function snapshot(): array
    {
        return [
            'source_sufficiency' => $this->sourceSufficiency,
            'warnings' => $this->warnings,
            'candidate_fragment_ids' => $this->candidateFragmentIds,
            'target_candidate_fragment_ids' => $this->targetCandidateFragmentIds,
            'supporting_candidate_fragment_ids' => $this->supportingCandidateFragmentIds,
            'requested_legal_issues' => $this->requestedLegalIssues,
            'search_queries' => $this->searchQueries,
            'response_id' => $this->responseId,
            'usage' => $this->usage,
            'model' => $this->model,
            'request_payload_hash' => $this->requestPayloadHash,
            'context_sufficiency' => $this->contextSufficiency,
        ];
    }
}
