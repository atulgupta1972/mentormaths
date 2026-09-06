<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentConceptPathProgress extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'student_concept_path_progress';

    protected $fillable = [
        'user_id',
        'student_enrollment_id',
        'textbook_chapter_id',
        'syllabus_chapter_id',
        'status',
        'cards_total',
        'cards_completed',
        'current_card_index',
        'events',
        'started_at',
        'completed_at',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function textbookChapter(): BelongsTo
    {
        return $this->belongsTo(TextbookChapter::class);
    }

    public function syllabusChapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
