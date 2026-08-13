<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PracticeCorrectionItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CORRECTED = 'corrected';

    public const SOURCE_GUIDED_PRACTICE = 'guided_practice';

    public const SOURCE_BATCH_TEST = 'batch_test';

    public const SOURCE_WRITTEN = 'written';

    public const CORRECTED_IN_DAILY_DRILL = 'daily_drill';

    public const CORRECTED_IN_STUDY_PLAN = 'study_plan';

    public const CORRECTED_IN_GUIDED_PRACTICE = 'guided_practice';

    public const CORRECTED_IN_BATCH_TEST = 'batch_test';

    public const REASON_TEACHER_HELP = 'teacher_help';

    protected $fillable = [
        'student_id',
        'question_id',
        'syllabus_chapter_id',
        'worksheet_id',
        'set_assignment_id',
        'set_attempt_id',
        'guided_attempt_question_id',
        'written_submission_id',
        'source_type',
        'failure_reason',
        'status',
        'first_failure_at',
        'corrected_at',
        'corrected_in',
    ];

    protected function casts(): array
    {
        return [
            'first_failure_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SetAssignment::class, 'set_assignment_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
