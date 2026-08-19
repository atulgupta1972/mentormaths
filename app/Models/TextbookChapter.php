<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TextbookChapter extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_REVIEW = 'review';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'textbook_id',
        'syllabus_chapter_id',
        'chapter_number',
        'title',
        'pdf_path',
        'status',
        'extraction_items',
        'mcq_set_plan',
        'extraction_error',
        'extracted_at',
        'mcq_worksheet_id',
        'mcq_worksheet_ids',
        'written_worksheet_id',
        'fill_blank_worksheet_id',
        'published_at',
        'published_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'extraction_items' => 'array',
            'mcq_set_plan' => 'array',
            'mcq_worksheet_ids' => 'array',
            'extracted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function textbook(): BelongsTo
    {
        return $this->belongsTo(Textbook::class);
    }

    public function syllabusChapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class);
    }

    public function mcqWorksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class, 'mcq_worksheet_id');
    }

    /**
     * @return list<int>
     */
    public function mcqWorksheetIds(): array
    {
        $ids = array_values(array_filter(
            $this->mcq_worksheet_ids ?? [],
            fn ($id) => is_numeric($id),
        ));

        if ($ids !== []) {
            return array_map('intval', $ids);
        }

        return $this->mcq_worksheet_id ? [(int) $this->mcq_worksheet_id] : [];
    }

    /**
     * @return list<int>
     */
    public function allWorksheetIds(): array
    {
        return array_values(array_unique(array_filter([
            ...$this->mcqWorksheetIds(),
            (int) ($this->fill_blank_worksheet_id ?? 0),
            (int) ($this->written_worksheet_id ?? 0),
        ])));
    }

    public function writtenWorksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class, 'written_worksheet_id');
    }

    public function fillBlankWorksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class, 'fill_blank_worksheet_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pdfUrl(): ?string
    {
        return $this->pdf_path
            ? Storage::disk('public')->url($this->pdf_path)
            : null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_EXTRACTING => 'Extracting…',
            self::STATUS_REVIEW => 'Ready for review',
            self::STATUS_PUBLISHED => 'Published',
            self::STATUS_FAILED => 'Import failed',
            default => 'Awaiting MCQ import',
        };
    }

    public function displayChapterNumber(): string
    {
        $fromSyllabus = trim((string) ($this->syllabusChapter?->chapter_number ?? ''));

        return $fromSyllabus !== '' ? $fromSyllabus : (string) ($this->chapter_number ?? '');
    }

    public function displayTitle(): string
    {
        $fromSyllabus = trim((string) ($this->syllabusChapter?->name ?? ''));

        return $fromSyllabus !== '' ? $fromSyllabus : (string) ($this->title ?? '');
    }

    public function displayChapterHeadName(): ?string
    {
        $name = trim((string) ($this->syllabusChapter?->chapterHead?->name ?? ''));

        return $name !== '' ? $name : null;
    }
}
