<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SetAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const MODE_BATCH = 'batch';

    public const MODE_GUIDED = 'guided';

    public const TIMING_ON_TIME = 'on_time';

    public const TIMING_LATE = 'late';

    protected $fillable = [
        'set_assignment_id',
        'attempt_number',
        'mode',
        'current_question_index',
        'started_at',
        'active_seconds',
        'active_session_started_at',
        'tab_leave_count',
        'completed_at',
        'score',
        'max_score',
        'first_try_correct_count',
        'corrected_after_help_count',
        'given_up_count',
        'time_seconds',
        'status',
        'submission_timing',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'active_session_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SetAssignment::class, 'set_assignment_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SetAttemptAnswer::class);
    }

    public function guidedQuestions(): HasMany
    {
        return $this->hasMany(GuidedAttemptQuestion::class)->orderBy('sort_order');
    }

    public function isGuided(): bool
    {
        return $this->mode === self::MODE_GUIDED;
    }
}
