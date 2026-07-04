<?php

namespace App\Models;

use App\Support\AssignmentProgress;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SetAssignment extends Model
{
    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'student_enrollment_id',
        'worksheet_id',
        'exam_plan_id',
        'assigned_by',
        'assigned_at',
        'reassigned_at',
        'due_date',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'reassigned_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    public function isOverdue(): bool
    {
        return AssignmentProgress::isOverdue($this);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function practiceSet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class, 'worksheet_id');
    }

    public function examPlan(): BelongsTo
    {
        return $this->belongsTo(ExamPlan::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SetAttempt::class)->orderBy('attempt_number');
    }

    public function latestAttempt(): ?SetAttempt
    {
        return $this->attempts()->latest('attempt_number')->first();
    }
}
