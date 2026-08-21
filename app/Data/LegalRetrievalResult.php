<?php

namespace App\Data;

final readonly class LegalRetrievalResult
{
    public function __construct(
        public array $fragments,
        public array $sourceSnapshots,
        public string $queryHash,
        public string $contextHash,
        public string $retrievalVersion,
        public int $totalChars,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->fragments === [];
    }

    public function fragmentMap(): array
    {
        $map = [];

        foreach ($this->fragments as $fragment) {
            $map[$fragment->fragmentId] = $fragment;
        }

        return $map;
    }

    public function promptContext(): string
    {
        return collect($this->fragments)
            ->map(fn (LegalContextFragment $fragment) => $fragment->toPromptBlock())
            ->implode("\n\n-----------------------------\n\n");
    }

    public function toSnapshot(): array
    {
        return [
            'retrieval_version' => $this->retrievalVersion,
            'query_hash' => $this->queryHash,
            'context_hash' => $this->contextHash,
            'total_chars' => $this->totalChars,
            'source_snapshots' => $this->sourceSnapshots,
            'retrieval_context' => array_map(
                fn (LegalContextFragment $fragment) => $fragment->toArray(),
                $this->fragments,
            ),
        ];
    }
}
