<?php

namespace App\Models;

use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
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
    ];

    protected $appends = [
        'tier_label',
        'tier_tagline',
        'display_title',
    ];

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
        $scopeLabel = $this->isChapterScope() ? 'Chapter test' : $this->tier_label;

        return ($this->set_code ? $this->set_code.' · ' : '')
            ."Set {$this->set_number} · {$scopeLabel} · {$count} sums";
    }

    public function scopeLabel(): string
    {
        return PracticeSetScope::label($this->scope ?? PracticeSetScope::TOPIC);
    }
}
