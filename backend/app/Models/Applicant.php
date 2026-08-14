<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Applicant extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'full_name',
        'email',
        'phone',
        'registration_number',
        'national_id',
        'gender',
        'county',
        'is_pwd',
        'pwd_details',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_pwd' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Applicant $applicant): void {
            if (empty($applicant->uuid)) {
                $applicant->uuid = (string) Str::uuid();
            }
        });
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
