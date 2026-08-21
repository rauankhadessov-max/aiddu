<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
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
}
