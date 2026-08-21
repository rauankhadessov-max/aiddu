<?php

namespace App\Data;

final readonly class CitationValidationResult
{
    public function __construct(
        public array $acceptedFindings,
        public array $rejectedFindings,
        public string $validatorVersion,
    ) {
    }

    public function toAudit(): array
    {
        return [
            'validator_version' => $this->validatorVersion,
            'accepted_count' => count($this->acceptedFindings),
            'rejected_count' => count($this->rejectedFindings),
            'accepted' => array_map(
                fn (array $finding) => [
                    'index' => $finding['_model_index'],
                    'title' => $finding['title'],
                    'citations' => $finding['citations'],
                ],
                $this->acceptedFindings,
            ),
            'rejected' => $this->rejectedFindings,
        ];
    }
}
