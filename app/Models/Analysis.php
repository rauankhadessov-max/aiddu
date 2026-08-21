<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Analysis extends Model
{
    protected $fillable = [
        'workspace_id',
        'document_id',
        'user_id',
        'title',
        'analysis_type',
        'instruction',
        'status',
        'version',
        'ai_model',
        'settings',
        'started_at',
        'completed_at',
        'summary',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceVersions(): BelongsToMany
    {
        return $this->belongsToMany(
            SourceVersion::class,
            'analysis_source_versions'
        )
            ->withPivot('role')
            ->withTimestamps();
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AnalysisFinding::class);
    }

    public function draftPackage(): HasOne
    {
        return $this->hasOne(DraftPackage::class);
    }
}
