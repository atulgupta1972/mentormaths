<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MmmCard extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const KIND_TABLE_FILL = 'table_fill';

    public const KIND_BALANCE_EQUATION = 'balance_equation';

    public const KIND_BODMAS = 'bodmas';

    public const KIND_CONGRUENCE = 'congruence';

    public const KIND_FILL_PAGE = 'fill_page';

    protected $fillable = [
        'slug',
        'title',
        'heading',
        'chapter_head_id',
        'sub_heading',
        'kind',
        'status',
        'accent',
        'method_blurb',
        'table_schema',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'table_schema' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(MmmCardExercise::class)->orderBy('sort_order')->orderBy('id');
    }

    public function chapterHead(): BelongsTo
    {
        return $this->belongsTo(ChapterHead::class);
    }

    public function gradeLevels(): BelongsToMany
    {
        return $this->belongsToMany(GradeLevel::class, 'mmm_card_grade_level');
    }

    public function syllabusChapters(): BelongsToMany
    {
        return $this->belongsToMany(SyllabusChapter::class, 'mmm_card_syllabus_chapter')
            ->withTimestamps();
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
