<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    public const TYPE_MCQ = 'mcq';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    public const SOURCE_PDF = 'pdf';

    protected $fillable = [
        'syllabus_topic_id',
        'type',
        'question_text',
        'explanation',
        'difficulty',
        'source',
        'created_by',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SyllabusTopic::class, 'syllabus_topic_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function worksheets(): BelongsToMany
    {
        return $this->belongsToMany(Worksheet::class, 'worksheet_question')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function correctOption(): ?QuestionOption
    {
        return $this->options->firstWhere('is_correct', true)
            ?? $this->options()->where('is_correct', true)->first();
    }
}
