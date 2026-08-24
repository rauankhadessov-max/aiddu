<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Source extends Model
{
    use SoftDeletes {
        bootSoftDeletes as private bootSoftDeletesTrait;
        initializeSoftDeletes as private initializeSoftDeletesTrait;
    }

    private static ?bool $ownershipSchemaAvailable = null;

    public static function bootSoftDeletes(): void
    {
        if (static::supportsOwnership()) {
            static::bootSoftDeletesTrait();
        }
    }

    public function initializeSoftDeletes(): void
    {
        if (static::supportsOwnership()) {
            $this->initializeSoftDeletesTrait();
        }
    }

    public static function supportsOwnership(): bool
    {
        return static::$ownershipSchemaAvailable ??= Schema::hasColumns('sources', ['user_id', 'deleted_at']);
    }

    protected $fillable = [
        'title',
        'type',
        'number',
        'adoption_date',
        'issuing_authority',
        'status',
        'official_url',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'adoption_date' => 'date',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SourceVersion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_sources')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function regulatoryProfiles(): BelongsToMany
    {
        return $this->belongsToMany(RegulatoryProfile::class, 'regulatory_profile_sources')
            ->withPivot(['sort_order', 'is_primary'])
            ->withTimestamps();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (!static::supportsOwnership()) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->whereNull('user_id')
                ->orWhere('user_id', $user->id);
        });
    }

    public function isGlobal(): bool
    {
        return $this->user_id === null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id !== null && $this->user_id === $user->id;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'law' => 'Закон',
            'code' => 'Кодекс',
            'government_resolution' => 'Постановление Правительства',
            'order' => 'Приказ',
            'rules' => 'Правила',
            'methodology' => 'Методика',
            default => 'Иное',
        };
    }
}
