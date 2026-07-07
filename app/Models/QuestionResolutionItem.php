<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionResolutionItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const CLEARANCE_ANSWERED = 'answered';

    public const CLEARANCE_ACKNOWLEDGED = 'acknowledged';

    protected $fillable = [
        'student_enrollment_id',
        'question_id',
        'set_assignment_id',
        'set_attempt_id',
        'guided_attempt_question_id',
        'status',
        'gave_up_at',
        'resolved_at',
        'clearance_method',
    ];

    protected function casts(): array
    {
        return [
            'gave_up_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
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
}
