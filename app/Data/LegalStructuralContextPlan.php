<?php

namespace App\Data;

final readonly class LegalStructuralContextPlan
{
    public function __construct(
        public array $mandatoryGroups,
        public ContextSufficiencyResult $sufficiency,
        public array $intents = [],
    ) {}

    public function isApplicable(): bool
    {
        return $this->sufficiency->applicable;
    }

    public function mandatoryFragmentIds(): array
    {
        $ids = [];

        foreach ($this->mandatoryGroups as $group) {
            foreach ($group['fragments'] as $fragment) {
                $ids[] = $fragment->fragmentId;
            }
        }

        return array_values(array_unique($ids));
    }
}
