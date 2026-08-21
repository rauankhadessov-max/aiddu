<?php

namespace App\Data;

final readonly class ContextSufficiencyResult
{
    public function __construct(
        public string $status = 'not_applicable',
        public bool $applicable = false,
        public ?string $targetMode = null,
        public bool $targetResolved = false,
        public bool $targetFound = false,
        public bool $parentFound = false,
        public bool $anchorsFound = false,
        public bool $mandatoryContextComplete = false,
        public array $targets = [],
        public array $missingElements = [],
        public array $ambiguities = [],
        public array $reasons = [],
    ) {}

    public function withMandatorySelection(bool $complete, array $missing = [], array $reasons = []): self
    {
        $missingElements = array_values(array_unique(array_merge($this->missingElements, $missing)));
        $allReasons = array_values(array_unique(array_merge($this->reasons, $reasons)));
        $status = $this->status;

        if ($this->applicable) {
            $status = $complete && $status !== 'insufficient' ? 'sufficient' : 'insufficient';
        }

        return new self(
            status: $status,
            applicable: $this->applicable,
            targetMode: $this->targetMode,
            targetResolved: $this->targetResolved,
            targetFound: $this->targetFound,
            parentFound: $this->parentFound,
            anchorsFound: $this->anchorsFound,
            mandatoryContextComplete: $complete,
            targets: $this->targets,
            missingElements: $missingElements,
            ambiguities: $this->ambiguities,
            reasons: $allReasons,
        );
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'applicable' => $this->applicable,
            'target_mode' => $this->targetMode,
            'target_resolved' => $this->targetResolved,
            'target_found' => $this->targetFound,
            'parent_found' => $this->parentFound,
            'anchors_found' => $this->anchorsFound,
            'mandatory_context_complete' => $this->mandatoryContextComplete,
            'targets' => $this->targets,
            'missing_elements' => $this->missingElements,
            'ambiguities' => $this->ambiguities,
            'reasons' => $this->reasons,
        ];
    }
}
