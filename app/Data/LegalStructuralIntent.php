<?php

namespace App\Data;

final readonly class LegalStructuralIntent
{
    public function __construct(
        public string $elementType,
        public string $locator,
        public string $targetMode,
        public string $evidence,
        public ?string $article = null,
        public ?string $paragraph = null,
        public ?string $subparagraph = null,
    ) {}

    public function toArray(): array
    {
        return [
            'element_type' => $this->elementType,
            'locator' => $this->locator,
            'target_mode' => $this->targetMode,
            'evidence' => $this->evidence,
            'article' => $this->article,
            'paragraph' => $this->paragraph,
            'subparagraph' => $this->subparagraph,
        ];
    }
}
