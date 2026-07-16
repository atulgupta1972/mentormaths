<?php

namespace App\Models;

use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worksheet extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'title',
        'set_number',
        'set_code',
        'tier',
        'scope',
        'syllabus_topic_id',
        'syllabus_chapter_id',
        'status',
        'notes',
        'created_by',
        'purpose',
        'catch_up_parent_worksheet_id',
        'catch_up_for_enrollment_id',
        'catch_up_source_question_ids',
    ];

    protected $appends = [
        'tier_label',
        'tier_tagline',
        'display_title',
    ];

    protected function casts(): array
    {
        return [
            'catch_up_source_question_ids' => 'array',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SyllabusTopic::class, 'syllabus_topic_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class, 'syllabus_chapter_id');
    }

    public function isChapterScope(): bool
    {
        return $this->scope === PracticeSetScope::CHAPTER;
    }

    public function isChapterTest(): bool
    {
        return $this->isChapterScope() && $this->tier === PracticeSetTier::CHAPTER_TEST;
    }

    public function isChapterPractice(): bool
    {
        return $this->isChapterScope() && ! $this->isChapterTest();
    }

    public function isCatchUp(): bool
    {
        return ($this->purpose ?? WorksheetPurpose::STANDARD) === WorksheetPurpose::CATCH_UP;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function catchUpParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'catch_up_parent_worksheet_id');
    }

    public function catchUpEnrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'catch_up_for_enrollment_id');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'worksheet_question')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SetAssignment::class, 'worksheet_id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(QuestionSetAudit::class);
    }

    public function latestAudit(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(QuestionSetAudit::class)->latestOfMany();
    }

    public function getTierLabelAttribute(): string
    {
        return PracticeSetTier::label($this->tier ?? PracticeSetTier::STARTER);
    }

    public function getTierTaglineAttribute(): string
    {
        return PracticeSetTier::tagline($this->tier ?? PracticeSetTier::STARTER);
    }

    public function getDisplayTitleAttribute(): string
    {
        $count = $this->questions_count ?? $this->questions()->count();

        if ($this->isCatchUp()) {
            return ($this->set_code ? $this->set_code.' · ' : '')
                ."Catch-up · {$count} sums";
        }

        $scopeLabel = $this->isChapterScope() ? 'Chapter test' : $this->tier_label;

        return ($this->set_code ? $this->set_code.' · ' : '')
            ."Set {$this->set_number} · {$scopeLabel} · {$count} sums";
    }

    public function scopeLabel(): string
    {
        return PracticeSetScope::label($this->scope ?? PracticeSetScope::TOPIC);
    }
}
