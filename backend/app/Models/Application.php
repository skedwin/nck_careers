<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Application extends Model
{
    use HasFactory;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_ELIGIBLE = 'eligible';

    public const STATUS_NOT_ELIGIBLE = 'not_eligible';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_SHORTLISTED = 'shortlisted';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'uuid',
        'application_reference',
        'applicant_id',
        'position_id',
        'mail_message_id',
        'subject',
        'status',
        'screening_status',
        'source',
        'received_at',
        'notes',
        'assigned_to',
        'nature_of_application',
        'nature_of_application_detail',
        'highest_qualification',
        'highest_qualification_detail',
        'management_course',
        'leadership_course',
        'professional_membership',
        'professional_qualifications',
        'experience_summary',
        'experience_years',
        'certifications_skills',
        'computer_proficiency',
        'profile_extraction',
        'profile_extracted_at',
        'duplicate_hidden_at',
        'duplicate_hidden_by',
        'duplicate_of_application_id',
        'duplicate_of_reference',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'profile_extracted_at' => 'datetime',
            'duplicate_hidden_at' => 'datetime',
            'profile_extraction' => 'array',
            'experience_years' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Application $application): void {
            if (empty($application->uuid)) {
                $application->uuid = (string) Str::uuid();
            }
        });
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function mailMessage(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class);
    }

    public function screeningResults(): HasMany
    {
        return $this->hasMany(ScreeningResult::class);
    }

    public function aiExtractions(): HasMany
    {
        return $this->hasMany(AiExtraction::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_application_id');
    }

    public function duplicateHiddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'duplicate_hidden_by');
    }

    public function isDuplicateHidden(): bool
    {
        return $this->duplicate_hidden_at !== null;
    }

    /**
     * Mailbox / job-board applications used in the official long listing.
     */
    public function scopeNotMyJobs(Builder $query, string $column = 'source'): Builder
    {
        return $query->where(function (Builder $builder) use ($column): void {
            $builder->whereNull($column)->orWhere($column, '!=', 'myjobs');
        });
    }

    public function scopeMyJobs(Builder $query, string $column = 'source'): Builder
    {
        return $query->where($column, 'myjobs');
    }
}
