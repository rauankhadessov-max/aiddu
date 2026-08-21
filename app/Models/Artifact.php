<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Artifact extends Model
{
    protected $fillable = [
        'draft_package_id',
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
}
