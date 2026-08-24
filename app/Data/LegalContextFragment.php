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
        public ?string $section = null,
        public ?string $chapter = null,
        public ?string $part = null,
        public ?string $appendix = null,
        public ?string $elementType = null,
        public ?string $elementLabel = null,
        public ?string $textParagraph = null,
    ) {}

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
            section: $this->section,
            chapter: $this->chapter,
            part: $this->part,
            appendix: $this->appendix,
            elementType: $this->elementType,
            elementLabel: $this->elementLabel,
            textParagraph: $this->textParagraph,
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
            'section' => $this->section,
            'chapter' => $this->chapter,
            'part' => $this->part,
            'appendix' => $this->appendix,
            'element_type' => $this->elementType,
            'element_label' => $this->elementLabel,
            'text_paragraph' => $this->textParagraph,
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
            'section' => $this->section,
            'chapter' => $this->chapter,
            'part' => $this->part,
            'appendix' => $this->appendix,
            'element_type' => $this->elementType,
            'element_label' => $this->elementLabel,
            'text_paragraph' => $this->textParagraph,
        ];

        return json_encode(
            $metadata,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\nTEXT:\n{$this->text}";
    }

    public function trustedReference(): string
    {
        $versionReference = preg_match('/^редакция\b/iu', trim($this->versionName)) === 1
            ? trim($this->versionName)
            : 'редакция '.trim($this->versionName);
        $parts = [
            $this->sourceTitle,
            $versionReference,
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

        if ($this->textParagraph !== null) {
            $parts[] = 'абзац '.$this->textParagraph;
        }

        if ($this->appendix !== null) {
            $parts[] = 'приложение '.$this->appendix;
        }

        return implode(', ', $parts).' ['.$this->fragmentId.']';
    }
}
