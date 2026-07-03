<?php

namespace App\Models;

use App\Support\PracticeSetScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyllabusChapter extends Model
{
    protected $fillable = [
        'syllabus_version_id',
        'chapter_head_id',
        'chapter_number',
        'name',
        'sort_order',
    ];

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

    public function chapterPracticeSets(): HasMany
    {
        return $this->hasMany(Worksheet::class, 'syllabus_chapter_id')
            ->where('scope', PracticeSetScope::CHAPTER)
            ->orderBy('set_number');
    }
}
