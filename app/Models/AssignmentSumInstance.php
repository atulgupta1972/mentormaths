<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentSumInstance extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CORRECT = 'correct';

    public const STATUS_WRONG = 'wrong';

    protected $fillable = [
        'set_assignment_id',
        'student_id',
        'worksheet_id',
        'question_id',
        'source_instance_id',
        'generation',
        'status',
        'set_attempt_id',
        'guided_attempt_question_id',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'evaluated_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCorrect(): bool
    {
        return $this->status === self::STATUS_CORRECT;
    }

    public function isWrong(): bool
    {
        return $this->status === self::STATUS_WRONG;
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SetAssignment::class, 'set_assignment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_instance_id');
    }

    public function remediations(): HasMany
    {
        return $this->hasMany(self::class, 'source_instance_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SetAttempt::class, 'set_attempt_id');
    }
}
