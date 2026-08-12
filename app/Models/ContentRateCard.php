<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentRateCard extends Model
{
    public const TYPE_TEXTBOOK_CHAPTER_MCQ = 'textbook_chapter_mcq';

    public const BASIS_PER_SET = 'per_set';

    public const BASIS_PER_QUESTION = 'per_question';

    protected $fillable = [
        'board_id',
        'grade_level_id',
        'syllabus_chapter_id',
        'content_type',
        'rate_basis',
        'default_amount_inr',
        'admin_notes',
        'created_by',
        'updated_by',
    ];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function syllabusChapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class);
    }

    public static function basisLabel(?string $basis): string
    {
        return match ($basis) {
            self::BASIS_PER_QUESTION => 'Per question',
            default => 'Per chapter / set',
        };
    }
}
