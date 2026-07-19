<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WrittenSubmissionItem extends Model
{
    protected $fillable = [
        'written_submission_id',
        'question_id',
        'question_number',
        'extracted_answer',
        'step_feedback',
        'score',
        'max_score',
        'is_correct',
        'confidence',
        'needs_review',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(WrittenSubmission::class, 'written_submission_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
