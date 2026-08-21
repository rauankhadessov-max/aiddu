<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftPackage extends Model
{
    protected $fillable = [
        'analysis_id',
        'title',
        'package_type',
        'status',
        'description',
        'plan',
        'approved_at',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'approved_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }
}
