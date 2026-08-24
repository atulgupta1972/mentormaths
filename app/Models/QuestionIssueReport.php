<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionIssueReport extends Model
{
    public const STATUS_PENDING_ADMIN = 'pending_admin';

    public const STATUS_SENT_TO_UPLOADER = 'sent_to_uploader';

    public const STATUS_AWAITING_REATTEMPT = 'awaiting_reattempt';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_DISMISSED = 'dismissed';

    public const CONTEXT_GUIDED = 'guided';

    public const CONTEXT_BATCH = 'batch';

    public const CONTEXT_FORMULA_DRILL = 'formula_drill';

    public const REASON_MISPRINT_INCOMPLETE = 'misprint_incomplete';

    public const REASON_QUESTION_CORRECT = 'question_correct';

    protected $fillable = [
        'student_id',
        'student_enrollment_id',
        'question_id',
        'set_assignment_id',
        'set_attempt_id',
        'guided_attempt_question_id',
        'formula_drill_item_id',
        'context',
        'reason',
        'status',
        'reported_at',
        'resolved_by',
        'resolved_at',
        'admin_note',
        'score_forfeited',
    ];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'resolved_at' => 'datetime',
            'score_forfeited' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SetAssignment::class, 'set_assignment_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SetAttempt::class, 'set_attempt_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isPendingAdmin(): bool
    {
        return $this->status === self::STATUS_PENDING_ADMIN;
    }

    public function isSentToUploader(): bool
    {
        return $this->status === self::STATUS_SENT_TO_UPLOADER;
    }

    /** Still needs admin Fixed / Dismiss (pending check or waiting on uploader). */
    public function isOpenForAdmin(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING_ADMIN,
            self::STATUS_SENT_TO_UPLOADER,
        ], true);
    }
}
