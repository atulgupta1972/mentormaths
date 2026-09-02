<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentChapterMetric extends Model
{
    protected $fillable = [
        'student_enrollment_id',
        'syllabus_chapter_id',
        'performance',
        'metrics_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'performance' => 'array',
            'metrics_updated_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class, 'syllabus_chapter_id');
    }
}
