<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentChapterCoverage extends Model
{
    public const STATUS_STUDIED = 'studied';

    public const STATUS_UNDER_STUDY = 'under_study';

    protected $fillable = [
        'student_enrollment_id',
        'syllabus_chapter_id',
        'status',
        'studied_at',
        'marked_under_study_at',
    ];

    protected function casts(): array
    {
        return [
            'studied_at' => 'datetime',
            'marked_under_study_at' => 'datetime',
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

    public function isStudied(): bool
    {
        return $this->status === self::STATUS_STUDIED;
    }

    public function isUnderStudy(): bool
    {
        return $this->status === self::STATUS_UNDER_STUDY;
    }
}
