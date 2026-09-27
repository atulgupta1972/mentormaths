<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentChapterTerm extends Model
{
    public const TERM_FIRST = 1;

    public const TERM_SECOND = 2;

    protected $fillable = [
        'student_enrollment_id',
        'syllabus_chapter_id',
        'term',
    ];

    protected function casts(): array
    {
        return [
            'term' => 'integer',
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
