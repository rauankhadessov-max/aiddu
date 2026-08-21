<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalysisFinding extends Model
{
    protected $fillable = [
        'analysis_id',
        'finding_type',
        'severity',
        'status',
        'title',
        'description',
        'document_fragment',
        'document_location',
        'source_reference',
        'legal_basis',
        'recommendation',
        'recommended_text',
        'justification',
        'confidence_score',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }
}
