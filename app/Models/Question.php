<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Question extends Model
{
    public const TYPE_MCQ = 'mcq';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    public const SOURCE_PDF = 'pdf';

    public const BANK_PRACTICE_SET = 'practice_set';

    public const BANK_CHAPTER_TEST = 'chapter_test';

    protected $fillable = [
        'syllabus_topic_id',
        'type',
        'question_text',
        'diagram_path',
        'explanation',
        'method_hint',
        'difficulty',
        'source',
        'bank_purpose',
        'created_by',
    ];

    protected $hidden = [
        'diagram_path',
    ];

    protected $appends = [
        'diagram_url',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Question $question) {
            if ($question->diagram_path) {
                Storage::disk('public')->delete($question->diagram_path);
            }
        });
    }

    protected function diagramUrl(): Attribute
    {
        return Attribute::get(fn () => $this->diagram_path
            ? Storage::disk('public')->url($this->diagram_path)
            : null);
    }

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
