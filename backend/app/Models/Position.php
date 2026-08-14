<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Position extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'title',
        'reference_code',
        'description',
        'department',
        'grade',
        'status',
        'vacancies',
        'sort_order',
        'opens_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'vacancies' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Position $position): void {
            if (empty($position->uuid)) {
                $position->uuid = (string) Str::uuid();
            }
        });
    }

    public function criteria(): HasMany
    {
        return $this->hasMany(PositionCriterion::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
