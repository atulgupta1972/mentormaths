<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormulaQuestionStat extends Model
{
    protected $fillable = [
        'student_id',
        'question_id',
        'total_failures',
        'times_shown',
        'times_correct',
        'times_exhausted',
        'needs_review',
        'last_shown_date',
        'last_correct_at',
    ];

    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'last_shown_date' => 'date',
            'last_correct_at' => 'datetime',
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
}
