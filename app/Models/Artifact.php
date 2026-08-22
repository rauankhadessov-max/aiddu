<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Artifact extends Model
{
    protected $fillable = [
        'draft_package_id',
        'source_artifact_id',
        'created_by',
        'artifact_type',
        'format',
        'title',
        'content',
        'storage_disk',
        'storage_path',
        'filename',
        'mime_type',
        'file_size',
        'renderer_version',
        'source_content_hash',
        'logical_content_hash',
        'binary_sha256',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function draftPackage(): BelongsTo
    {
        return $this->belongsTo(DraftPackage::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sourceArtifact(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_artifact_id');
    }

    public function representations(): HasMany
    {
        return $this->hasMany(self::class, 'source_artifact_id');
    }
}
