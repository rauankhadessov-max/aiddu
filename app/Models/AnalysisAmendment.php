<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisAmendment extends Model
{
    protected $fillable = [
        'analysis_id',
        'source_id',
        'source_version_id',
        'target_mode',
        'structural_element_type',
        'section',
        'chapter',
        'part',
        'article',
        'paragraph',
        'subparagraph',
        'text_paragraph',
        'appendix',
        'proposed_locator',
        'amendment_type',
        'disposition',
        'current_text',
        'proposed_text',
        'justification',
        'legal_basis',
        'source_reference',
        'confidence_score',
        'warnings',
        'target_fragment_ids',
        'anchor_fragment_ids',
        'citations',
        'target_snapshot',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'warnings' => 'array',
            'target_fragment_ids' => 'array',
            'anchor_fragment_ids' => 'array',
            'citations' => 'array',
            'target_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(SourceVersion::class);
    }
}
