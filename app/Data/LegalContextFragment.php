<?php

namespace App\Data;

final readonly class LegalContextFragment
{
    public function __construct(
        public string $fragmentId,
        public int $sourceId,
        public int $sourceVersionId,
        public string $sourceTitle,
        public string $versionName,
        public ?string $article,
        public ?string $paragraph,
        public ?string $subparagraph,
        public int $startOffset,
        public int $endOffset,
        public string $textHash,
        public string $text,
        public float $score = 0.0,
    ) {
    }

    public function withScore(float $score): self
    {
        return new self(
            fragmentId: $this->fragmentId,
            sourceId: $this->sourceId,
            sourceVersionId: $this->sourceVersionId,
            sourceTitle: $this->sourceTitle,
            versionName: $this->versionName,
            article: $this->article,
            paragraph: $this->paragraph,
            subparagraph: $this->subparagraph,
            startOffset: $this->startOffset,
            endOffset: $this->endOffset,
            textHash: $this->textHash,
            text: $this->text,
            score: $score,
        );
    }

    public function toArray(): array
    {
        return [
            'fragment_id' => $this->fragmentId,
            'source_id' => $this->sourceId,
            'source_version_id' => $this->sourceVersionId,
            'source_title' => $this->sourceTitle,
            'version_name' => $this->versionName,
            'article' => $this->article,
            'paragraph' => $this->paragraph,
            'subparagraph' => $this->subparagraph,
            'start_offset' => $this->startOffset,
            'end_offset' => $this->endOffset,
            'offset_unit' => 'unicode_codepoint',
            'text_hash' => $this->textHash,
            'retrieval_score' => round($this->score, 6),
            'text' => $this->text,
        ];
    }

    public function toPromptBlock(): string
    {
        $metadata = [
            'fragment_id' => $this->fragmentId,
            'source_id' => $this->sourceId,
            'source_version_id' => $this->sourceVersionId,
            'source_title' => $this->sourceTitle,
            'version_name' => $this->versionName,
            'article' => $this->article,
            'paragraph' => $this->paragraph,
            'subparagraph' => $this->subparagraph,
        ];

        return json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\nTEXT:\n{$this->text}";
    }

    public function trustedReference(): string
    {
        $parts = [
            $this->sourceTitle,
            'редакция '.$this->versionName,
        ];

        if ($this->article !== null) {
            $parts[] = 'статья '.$this->article;
        }

        if ($this->paragraph !== null) {
            $parts[] = 'пункт '.$this->paragraph;
        }

        if ($this->subparagraph !== null) {
            $parts[] = 'подпункт '.$this->subparagraph;
        }

        return implode(', ', $parts).' ['.$this->fragmentId.']';
    }
}
