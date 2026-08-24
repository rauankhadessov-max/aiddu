<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RegulatoryProfile extends Model
{
    public const NEW_USER_DEFAULT = 'new_user_default';

    protected $fillable = [
        'purpose',
        'name',
        'workspace_title',
        'workspace_description',
        'workspace_category',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Source::class, 'regulatory_profile_sources')
            ->withPivot(['sort_order', 'is_primary'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }
}
