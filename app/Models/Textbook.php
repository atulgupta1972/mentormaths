<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Textbook extends Model
{
    public const PRACTICE_LINE_STANDARD = 'standard';

    public const PRACTICE_LINE_MENTORMATHS = 'mentormaths';

    protected $fillable = [
        'grade_level_id',
        'board_id',
        'name',
        'code',
        'practice_line',
        'source_ref',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function isMentorMathsPracticeLine(): bool
    {
        return ($this->practice_line ?? self::PRACTICE_LINE_STANDARD) === self::PRACTICE_LINE_MENTORMATHS;
    }

    /**
     * True when this textbook is an RD Sharma (or RDS-coded) conversion/source book.
     * Used to hide those rows from Concept Builder — concepts belong on the school textbook.
     */
    public function looksLikeRdSharma(): bool
    {
        $name = strtolower(trim((string) $this->name));
        $code = strtolower(trim((string) $this->code));
        $source = strtoupper(trim((string) ($this->source_ref ?? '')));

        if ($source !== '' && str_starts_with($source, 'RDS')) {
            return true;
        }

        if ($code === 'rds' || $code === 'rdsharma' || str_starts_with($code, 'rds-') || str_starts_with($code, 'rds_')) {
            return true;
        }

        return str_contains($name, 'rd sharma')
            || str_contains($name, 'r.d. sharma')
            || str_contains($name, 'r.d.sharma')
            || preg_match('/\brds\b/', $name) === 1;
    }

    /**
     * Student-facing display name — never expose internal source_ref.
     */
    public function publicName(): string
    {
        return (string) $this->name;
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(TextbookChapter::class)->orderBy('chapter_number');
    }

    public function chapterMaps(): HasMany
    {
        return $this->hasMany(TextbookChapterMap::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
