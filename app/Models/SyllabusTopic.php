<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyllabusTopic extends Model
{
    protected $fillable = [
        'syllabus_chapter_id',
        'name',
        'learning_outcomes',
        'difficulty',
        'planned_periods',
        'remarks',
        'sort_order',
    ];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class, 'syllabus_chapter_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'syllabus_topic_id');
    }

    public function practiceSets(): HasMany
    {
        return $this->hasMany(Worksheet::class, 'syllabus_topic_id')->orderBy('set_number');
    }
}
