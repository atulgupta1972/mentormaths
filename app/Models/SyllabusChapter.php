<?php

namespace App\Models;

use App\Support\PracticeSetScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyllabusChapter extends Model
{
    protected $fillable = [
        'syllabus_version_id',
        'chapter_head_id',
        'chapter_number',
        'name',
        'sort_order',
        'ncert_verified',
    ];

    protected function casts(): array
    {
        return [
            'ncert_verified' => 'boolean',
        ];
    }

    public function chapterHead(): BelongsTo
    {
        return $this->belongsTo(ChapterHead::class);
    }

    public function syllabusVersion(): BelongsTo
    {
        return $this->belongsTo(SyllabusVersion::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(SyllabusTopic::class)->orderBy('sort_order');
    }

    public function textbookChapters(): HasMany
    {
        return $this->hasMany(TextbookChapter::class);
    }

    public function chapterPracticeSets(): HasMany
    {
        return $this->hasMany(Worksheet::class, 'syllabus_chapter_id')
            ->where('scope', PracticeSetScope::CHAPTER)
            ->orderBy('set_number');
    }

    public function examPlans(): BelongsToMany
    {
        return $this->belongsToMany(ExamPlan::class, 'exam_plan_chapters')
            ->withTimestamps();
    }

    public function numericChapterNumber(): int
    {
        $raw = trim((string) $this->chapter_number);

        if (preg_match('/(\d+)/', $raw, $matches)) {
            return (int) $matches[1];
        }

        return (int) ($this->sort_order ?: 0);
    }

    public static function orderKey(?string $chapterNumber): string
    {
        $original = trim((string) $chapterNumber);
        $text = $original;
        $part = 1;

        if (preg_match('/^\s*p(?:art)?\s*-?\s*(\d+)\s*[-–:,]?\s*/i', $text, $matches)) {
            $part = (int) $matches[1];
            $text = substr($text, strlen($matches[0]));
        }

        $chapter = 9999;
        if (preg_match('/(?:ch(?:apter)?|\bc)\s*-?\s*(\d+)/i', $text, $matches)
            || preg_match('/(\d+)/', $text, $matches)) {
            $chapter = (int) $matches[1];
        }

        return sprintf('%02d-%04d-%s', $part, $chapter, mb_strtolower($original));
    }
}
