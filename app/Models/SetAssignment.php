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
        'parent_assignment_id',
        'revision_number',
        'exam_plan_id',
        'effective_syllabus_chapter_id',
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
            'revision_number' => 'integer',
            'assigned_at' => 'datetime',
            'reassigned_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    public function isRevision(): bool
    {
        return ((int) $this->revision_number) > 0;
    }

    public function isOriginalLearning(): bool
    {
        return ! $this->isRevision();
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

    public function parentAssignment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_assignment_id');
    }

    public function childRevisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_assignment_id')->orderBy('revision_number');
    }

    public function sumInstances(): HasMany
    {
        return $this->hasMany(AssignmentSumInstance::class, 'set_assignment_id');
    }

    public function examPlan(): BelongsTo
    {
        return $this->belongsTo(ExamPlan::class);
    }

    public function effectiveChapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class, 'effective_syllabus_chapter_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(SetAttempt::class)->orderBy('attempt_number');
    }

    public function writtenSubmissions(): HasMany
    {
        return $this->hasMany(WrittenSubmission::class, 'set_assignment_id');
    }

    public function latestWrittenSubmission(): ?WrittenSubmission
    {
        return $this->writtenSubmissions()->latest('id')->first();
    }

    public function latestAttempt(): ?SetAttempt
    {
        return $this->attempts()->latest('attempt_number')->first();
    }
}
