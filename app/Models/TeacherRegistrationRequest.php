<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherRegistrationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COUNTER_OFFERED = 'counter_offered';

    public const STATUS_OFFER_ACCEPTED = 'offer_accepted';

    public const STATUS_OFFER_DECLINED = 'offer_declined';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const OFFER_ACCEPTED = 'accepted';

    public const OFFER_DECLINED = 'declined';

    /** @var array<string, string> */
    public const PLATFORM_USAGE_SCOPES = [
        'worksheets_only' => 'Mostly worksheets / PDFs only',
        'partial' => 'Some tracking (scores or attendance)',
        'full_tracking' => 'Full progress monitoring & analytics',
        'none' => 'No digital platform currently',
    ];

    /** @var array<string, string> */
    public const MENTOR_MATHS_FEATURES = [
        'formula_drills' => 'Formula drills (chapter-wise recall)',
        'basics_drills' => 'Basics drills (fundamentals)',
        'exam_plan' => 'Exam-wise monitoring & exam plan',
        'practice_sets_tiered' => 'Tiered practice sets (Starter / Builder / Champion)',
        'catch_up_sets' => 'Catch-up sets from weak / gap areas',
        'online_mcq_fill_blank' => 'Online MCQ & fill-in-blank with auto scoring',
        'written_sheets' => 'Written sheets (PDF + photo upload & grading)',
        'chapter_tests' => 'Chapter tests',
        'syllabus_chapter_hub' => 'CBSE / ICSE chapter-wise syllabus hub',
        'student_dashboard' => 'Student progress dashboard & assignment tracking',
    ];

    protected $fillable = [
        'name',
        'email',
        'mobile',
        'gender',
        'date_of_birth',
        'password',
        'city',
        'state',
        'country',
        'qualification',
        'current_role',
        'years_of_experience',
        'bio',
        'resume_path',
        'resume_original_name',
        'monitoring_platform_name',
        'platform_usage_scope',
        'current_tool_features',
        'platform_experience_notes',
        'board_ids',
        'teaching_grade_level_ids',
        'content_grade_level_ids',
        'interested_in_content_creation',
        'creates_mcq',
        'creates_fill_blank',
        'creates_written_sheets',
        'creates_chapter_tests',
        'creates_formula_drills',
        'sample_work_url',
        'interested_in_book_content_upload',
        'proposed_rate_per_set_inr',
        'interested_in_doubt_solving',
        'agreed_to_mentoring_program',
        'doubt_sessions_per_week',
        'doubt_hours_per_week',
        'proposed_hourly_rate_inr',
        'preferred_days',
        'preferred_time_slot',
        'expected_start_date',
        'counter_hourly_rate_inr',
        'counter_offer_message',
        'counter_offer_token',
        'profile_completion_token',
        'counter_offer_sent_at',
        'offer_responded_at',
        'offer_response',
        'teaches_english_medium',
        'teaches_hindi_medium',
        'regional_language',
        'referral_source',
        'agreed_to_terms',
        'agreed_at',
        'notes',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'user_id',
    ];

    protected $hidden = [
        'password',
        'counter_offer_token',
        'profile_completion_token',
    ];

    protected function casts(): array
    {
        return [
            'board_ids' => 'array',
            'teaching_grade_level_ids' => 'array',
            'content_grade_level_ids' => 'array',
            'preferred_days' => 'array',
            'current_tool_features' => 'array',
            'date_of_birth' => 'date',
            'interested_in_content_creation' => 'boolean',
            'creates_mcq' => 'boolean',
            'creates_fill_blank' => 'boolean',
            'creates_written_sheets' => 'boolean',
            'creates_chapter_tests' => 'boolean',
            'creates_formula_drills' => 'boolean',
            'interested_in_book_content_upload' => 'boolean',
            'interested_in_doubt_solving' => 'boolean',
            'agreed_to_mentoring_program' => 'boolean',
            'mentoring_agreed_at' => 'datetime',
            'doubt_hours_per_week' => 'decimal:1',
            'expected_start_date' => 'date',
            'counter_offer_sent_at' => 'datetime',
            'profile_completion_requested_at' => 'datetime',
            'offer_responded_at' => 'datetime',
            'agreed_to_terms' => 'boolean',
            'agreed_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCounterOffered(): bool
    {
        return $this->status === self::STATUS_COUNTER_OFFERED;
    }

    public function isOfferAccepted(): bool
    {
        return $this->status === self::STATUS_OFFER_ACCEPTED;
    }

    public function canSendCounterOffer(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_OFFER_DECLINED,
        ], true) && $this->interested_in_doubt_solving;
    }

    public function canRespondToOffer(): bool
    {
        return $this->status === self::STATUS_COUNTER_OFFERED
            && $this->counter_offer_token
            && $this->offer_response === null;
    }

    public function canApprove(): bool
    {
        if ($this->status === self::STATUS_APPROVED || $this->status === self::STATUS_REJECTED) {
            return false;
        }

        if ($this->status === self::STATUS_COUNTER_OFFERED) {
            return false;
        }

        if ($this->status === self::STATUS_OFFER_DECLINED) {
            return false;
        }

        return true;
    }

    public function agreedHourlyRateInr(): ?int
    {
        if ($this->status === self::STATUS_OFFER_ACCEPTED && $this->counter_hourly_rate_inr) {
            return (int) $this->counter_hourly_rate_inr;
        }

        return $this->proposed_hourly_rate_inr !== null
            ? (int) $this->proposed_hourly_rate_inr
            : null;
    }

    public function age(): ?int
    {
        if ($this->date_of_birth === null) {
            return null;
        }

        return $this->date_of_birth->age;
    }

    /**
     * @return list<string>
     */
    public function currentToolFeatureLabels(): array
    {
        return collect($this->current_tool_features ?? [])
            ->map(fn ($key) => self::MENTOR_MATHS_FEATURES[$key] ?? $key)
            ->values()
            ->all();
    }

    public function platformUsageScopeLabel(): ?string
    {
        if ($this->platform_usage_scope === null) {
            return null;
        }

        return self::PLATFORM_USAGE_SCOPES[$this->platform_usage_scope] ?? $this->platform_usage_scope;
    }

    /**
     * @return list<string>
     */
    public function languageLabels(): array
    {
        $labels = [];

        if ($this->teaches_english_medium) {
            $labels[] = 'English';
        }

        if ($this->teaches_hindi_medium) {
            $labels[] = 'Hindi';
        }

        if ($this->regional_language) {
            $labels[] = $this->regional_language;
        }

        return $labels;
    }

    public function locationLabel(): ?string
    {
        $parts = array_filter([
            $this->city,
            $this->state,
            $this->country ?: 'India',
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * @return list<string>
     */
    public function missingProfileFields(): array
    {
        $missing = [];

        if (blank($this->city)) {
            $missing[] = 'city';
        }

        if (blank($this->state)) {
            $missing[] = 'state';
        }

        if (blank($this->country)) {
            $missing[] = 'country';
        }

        if (! $this->teaches_english_medium && ! $this->teaches_hindi_medium) {
            $missing[] = 'language';
        }

        return $missing;
    }

    public function hasCompleteProfileDetails(): bool
    {
        return $this->missingProfileFields() === [];
    }

    public function canRequestProfileCompletion(): bool
    {
        if ($this->hasCompleteProfileDetails()) {
            return false;
        }

        return ! in_array($this->status, [
            self::STATUS_REJECTED,
        ], true);
    }

    public function canCompleteProfileViaToken(): bool
    {
        return (bool) $this->profile_completion_token
            && ! in_array($this->status, [
                self::STATUS_REJECTED,
            ], true);
    }

    public static function missingProfileFieldLabel(string $field): string
    {
        return match ($field) {
            'city' => 'City',
            'state' => 'State',
            'country' => 'Country',
            'language' => 'Language (English and/or Hindi)',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pending review',
            self::STATUS_COUNTER_OFFERED => 'Counter offer sent',
            self::STATUS_OFFER_ACCEPTED => 'Offer accepted',
            self::STATUS_OFFER_DECLINED => 'Offer declined',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_REJECTED => 'Rejected',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
