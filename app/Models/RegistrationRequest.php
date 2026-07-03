<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'academic_year_id',
        'board_id',
        'grade_level_id',
        'student_name',
        'date_of_birth',
        'student_mobile',
        'parent1_name',
        'parent1_mobile',
        'parent2_name',
        'parent2_mobile',
        'school_name',
        'email',
        'notes',
        'notify_student_mobile',
        'notify_parent1_mobile',
        'notify_parent2_mobile',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'student_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'reviewed_at' => 'datetime',
            'notify_student_mobile' => 'boolean',
            'notify_parent1_mobile' => 'boolean',
            'notify_parent2_mobile' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
