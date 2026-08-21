<?php

namespace App\Data;

final readonly class AmendmentValidationResult
{
    public function __construct(
        public array $acceptedAmendments,
        public array $rejectedAmendments,
        public string $validatorVersion,
    ) {}

    public function audit(): array
    {
        return [
            'validator_version' => $this->validatorVersion,
            'accepted_count' => count($this->acceptedAmendments),
            'rejected_count' => count($this->rejectedAmendments),
            'accepted' => array_map(fn (array $amendment) => [
                'index' => $amendment['_model_index'],
                'target_fragment_ids' => $amendment['target_fragment_ids'],
                'anchor_fragment_ids' => $amendment['anchor_fragment_ids'],
                'citations' => $amendment['citations'],
            ], $this->acceptedAmendments),
            'rejected' => $this->rejectedAmendments,
        ];
    }
}
