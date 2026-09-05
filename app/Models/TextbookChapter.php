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
        'concept_path_items',
        'concept_path_status',
        'concept_path_approved_at',
        'concept_path_approved_by',
        'extraction_error',
        'extracted_at',
        'mcq_worksheet_id',
        'mcq_worksheet_ids',
        'written_worksheet_id',
        'written_worksheet_ids',
        'fill_blank_worksheet_id',
        'fill_blank_worksheet_ids',
        'published_at',
        'published_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'extraction_items' => 'array',
            'mcq_set_plan' => 'array',
            'concept_path_items' => 'array',
            'concept_path_approved_at' => 'datetime',
            'mcq_worksheet_ids' => 'array',
            'fill_blank_worksheet_ids' => 'array',
            'written_worksheet_ids' => 'array',
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
    public function fillBlankWorksheetIds(): array
    {
        return $this->worksheetIdList($this->fill_blank_worksheet_ids ?? [], $this->fill_blank_worksheet_id);
    }

    /**
     * @return list<int>
     */
    public function writtenWorksheetIds(): array
    {
        return $this->worksheetIdList($this->written_worksheet_ids ?? [], $this->written_worksheet_id);
    }

    /**
     * @return list<array{part: int, mcq_worksheet_id: int|null, fill_blank_worksheet_id: int|null, written_worksheet_id: int|null}>
     */
    public function contentParts(): array
    {
        $mcq = $this->mcqWorksheetIds();
        $fill = $this->fillBlankWorksheetIds();
        $written = $this->writtenWorksheetIds();
        $count = max(count($mcq), count($fill), count($written));
        $parts = [];

        for ($index = 0; $index < $count; $index++) {
            $parts[] = [
                'part' => $index + 1,
                'mcq_worksheet_id' => $mcq[$index] ?? null,
                'fill_blank_worksheet_id' => $fill[$index] ?? null,
                'written_worksheet_id' => $written[$index] ?? null,
            ];
        }

        return $parts;
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private function worksheetIdList(mixed $ids, mixed $fallbackId): array
    {
        $list = array_values(array_filter(
            is_array($ids) ? $ids : [],
            fn ($id) => is_numeric($id),
        ));

        if ($list !== []) {
            return array_map('intval', $list);
        }

        return $fallbackId ? [(int) $fallbackId] : [];
    }

    /**
     * @return list<int>
     */
    public function allWorksheetIds(): array
    {
        return array_values(array_unique(array_filter([
            ...$this->mcqWorksheetIds(),
            ...$this->fillBlankWorksheetIds(),
            ...$this->writtenWorksheetIds(),
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
        // Prefer live syllabus numbering after syllabus revisions.
        $syllabus = trim((string) ($this->syllabusChapter?->chapter_number ?? ''));
        if ($syllabus !== '') {
            return preg_replace('/^Ch\s*/i', '', $syllabus) ?: $syllabus;
        }

        return trim((string) ($this->chapter_number ?? ''));
    }

    public function displayTitle(): string
    {
        // Prefer live syllabus chapter name after syllabus revisions.
        $syllabus = trim((string) ($this->syllabusChapter?->name ?? ''));
        if ($syllabus !== '') {
            return $syllabus;
        }

        return trim((string) ($this->title ?? ''));
    }

    public function displaySyllabusLabel(): string
    {
        $syllabus = $this->syllabusChapter;
        if (! $syllabus) {
            $number = $this->displayChapterNumber();
            $title = $this->displayTitle();

            return $number !== '' ? "Ch {$number} — {$title}" : $title;
        }

        $name = trim((string) $syllabus->name);
        if (preg_match('/^Ch\s*\d+/i', $name)) {
            return $name;
        }

        $number = preg_replace('/^Ch\s*/i', '', trim((string) ($syllabus->chapter_number ?? '')));
        $number = ltrim((string) $number, '0') ?: $number;

        return $number !== '' ? "Ch {$number} — {$name}" : $name;
    }

    /**
     * Keep stored book-chapter title/number aligned with the linked syllabus chapter.
     */
    public function syncDisplayFromSyllabus(): bool
    {
        $syllabus = $this->syllabusChapter;
        if (! $syllabus) {
            return false;
        }

        $updates = [];
        $name = trim((string) $syllabus->name);
        if ($name !== '' && $this->title !== $name) {
            $updates['title'] = $name;
        }

        $number = $syllabus->numericChapterNumber();
        if ($number > 0 && (int) $this->chapter_number !== $number) {
            $updates['chapter_number'] = $number;
        }

        if ($updates === []) {
            return false;
        }

        $this->update($updates);

        return true;
    }

    public function displayChapterHeadName(): ?string
    {
        $name = trim((string) ($this->syllabusChapter?->chapterHead?->name ?? ''));

        return $name !== '' ? $name : null;
    }
}
