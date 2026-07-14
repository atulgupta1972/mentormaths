<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamPlan extends Model
{
    public const TYPE_UNIT_TEST = 'unit_test';

    public const TYPE_HALF_YEARLY = 'half_yearly';

    public const TYPE_FINAL = 'final';

    public const TYPE_OTHER = 'other';

    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const TYPES = [
        self::TYPE_UNIT_TEST,
        self::TYPE_HALF_YEARLY,
        self::TYPE_FINAL,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'student_enrollment_id',
        'exam_date',
        'title',
        'exam_type',
        'notes',
        'obtained_marks',
        'total_marks',
        'marks_entered_at',
        'created_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'marks_entered_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function chapters(): BelongsToMany
    {
        return $this->belongsToMany(SyllabusChapter::class, 'exam_plan_chapters')
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(SyllabusTopic::class, 'exam_plan_topics')
            ->withTimestamps();
    }

    public function setAssignments(): HasMany
    {
        return $this->hasMany(SetAssignment::class);
    }

    public function typeLabel(): string
    {
        return match ($this->exam_type) {
            self::TYPE_UNIT_TEST => 'Unit test',
            self::TYPE_HALF_YEARLY => 'Half yearly',
            self::TYPE_FINAL => 'Final exam',
            default => 'Other',
        };
    }

    public function isUpcoming(): bool
    {
        return $this->status === self::STATUS_PLANNED
            && $this->exam_date->toDateString() >= now()->toDateString();
    }

    public function hasMarks(): bool
    {
        return $this->obtained_marks !== null && $this->total_marks !== null;
    }
}
