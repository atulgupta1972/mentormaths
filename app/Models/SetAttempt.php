<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SetAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const TIMING_ON_TIME = 'on_time';

    public const TIMING_LATE = 'late';

    protected $fillable = [
        'set_assignment_id',
        'attempt_number',
        'started_at',
        'completed_at',
        'score',
        'max_score',
        'time_seconds',
        'status',
        'submission_timing',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
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
}
