<?php

namespace App\Services;

use App\Models\GradeLevel;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Support\MentorMathsSourceRef;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MentorMathsConversionQueueService
{
    /** Minimum fill-in-blanks required before a chapter can be published. */
    public const MIN_FILL_BLANK_READY = 15;

    /**
     * Publisher practice books that should be converted one chapter at a time.
     */
    public function isConversionCandidate(Textbook $book): bool
    {
        if ($book->isMentorMathsPracticeLine()) {
            return true;
        }

        $code = strtolower(trim((string) $book->code));
        $name = mb_strtolower(trim((string) $book->name));

        if (in_array($code, ['rds', 'rs', 'gl', 'exem'], true)) {
            return true;
        }

        foreach (['sharma', 'aggarwal', 'agarwal', 'lakshmi', 'greya', 'exemplar'] as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function chapterIsPending(TextbookChapter $chapter): bool
    {
        $chapter->loadMissing('textbook');

        $book = $chapter->textbook;
        if (! $book || ! $this->isConversionCandidate($book)) {
            return false;
        }

        // Done = MentorMaths line + fill-blank worksheets published.
        if ($book->isMentorMathsPracticeLine() && $chapter->fillBlankWorksheetIds() !== []) {
            return false;
        }

        return true;
    }

    /**
     * @return array{
     *     chapters: list<array<string, mixed>>,
     *     books: list<array<string, mixed>>,
     *     grades: list<array{id: int, name: string}>,
     *     filters: array{grade_level_id: ?int, textbook_id: ?int},
     *     pending_count: int
     * }
     */
    public function queue(?int $gradeLevelId = null, ?int $textbookId = null): array
    {
        $grades = GradeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])
            ->values()
            ->all();

        $candidateBooks = Textbook::query()
            ->with('gradeLevel:id,name')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Textbook $book) => $this->isConversionCandidate($book))
            ->values();

        $booksForFilter = $candidateBooks
            ->when($gradeLevelId, fn (Collection $c) => $c->where('grade_level_id', $gradeLevelId))
            ->map(fn (Textbook $book) => [
                'id' => $book->id,
                'name' => $book->name,
                'code' => $book->code,
                'grade_level_id' => $book->grade_level_id,
                'grade_name' => $book->gradeLevel?->name,
                'practice_line' => $book->practice_line ?? Textbook::PRACTICE_LINE_STANDARD,
                'source_ref' => $book->source_ref,
                'is_mentormaths' => $book->isMentorMathsPracticeLine(),
                'label' => trim(($book->gradeLevel?->name ?? '').' · '.$book->name.' ('.$book->code.')'),
            ])
            ->values()
            ->all();

        $bookIds = $candidateBooks->pluck('id')->all();

        $chapters = TextbookChapter::query()
            ->with([
                'textbook:id,name,code,grade_level_id,practice_line,source_ref',
                'textbook.gradeLevel:id,name,sort_order',
                'syllabusChapter:id,name,chapter_number',
                'fillBlankWorksheet:id,set_code',
            ])
            ->whereIn('textbook_id', $bookIds !== [] ? $bookIds : [0])
            ->when($gradeLevelId, fn ($q) => $q->whereHas(
                'textbook',
                fn ($inner) => $inner->where('grade_level_id', $gradeLevelId),
            ))
            ->when($textbookId, fn ($q) => $q->where('textbook_id', $textbookId))
            ->orderBy('textbook_id')
            ->orderBy('chapter_number')
            ->get()
            ->filter(fn (TextbookChapter $chapter) => $this->chapterIsPending($chapter))
            ->map(fn (TextbookChapter $chapter) => $this->chapterRow($chapter))
            ->values()
            ->all();

        return [
            'chapters' => $chapters,
            'books' => $booksForFilter,
            'grades' => $grades,
            'filters' => [
                'grade_level_id' => $gradeLevelId,
                'textbook_id' => $textbookId,
            ],
            'pending_count' => count($chapters),
            'min_fill_blank_ready' => self::MIN_FILL_BLANK_READY,
        ];
    }

    public function fillBlankReadyCount(TextbookChapter $chapter): int
    {
        $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];

        return collect($items)->filter(
            fn ($item) => is_array($item)
                && filled($item['fill_blank_question_text'] ?? null)
                && filled($item['fill_blank_correct_answer'] ?? null)
                && empty($item['fill_blank_skipped']),
        )->count();
    }

    public function meetsPublishMinimum(TextbookChapter $chapter): bool
    {
        return $this->fillBlankReadyCount($chapter) >= self::MIN_FILL_BLANK_READY;
    }

    /**
     * @return array<string, mixed>
     */
    public function chapterRow(TextbookChapter $chapter): array
    {
        $chapter->syncDisplayFromSyllabus();
        $fillReady = $this->fillBlankReadyCount($chapter);
        $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];

        $book = $chapter->textbook;
        $classNumber = $this->classNumber($book?->gradeLevel?->name);
        $guessedRef = MentorMathsSourceRef::guessFromBook(
            (string) ($book?->name ?? ''),
            (string) ($book?->code ?? ''),
            $classNumber,
        );

        return [
            'id' => $chapter->id,
            'textbook_id' => $chapter->textbook_id,
            'book_name' => $book?->name,
            'book_code' => $book?->code,
            'grade_level_id' => $book?->grade_level_id,
            'grade_name' => $book?->gradeLevel?->name,
            'chapter_number' => $chapter->displayChapterNumber(),
            'title' => $chapter->displayTitle(),
            'label' => $chapter->displaySyllabusLabel(),
            'status' => $chapter->status,
            'status_label' => $chapter->statusLabel(),
            'items_count' => count($items),
            'fill_blank_ready_count' => $fillReady,
            'min_fill_blank_ready' => self::MIN_FILL_BLANK_READY,
            'meets_publish_minimum' => $fillReady >= self::MIN_FILL_BLANK_READY,
            'remaining_to_minimum' => max(0, self::MIN_FILL_BLANK_READY - $fillReady),
            'fill_blank_set_code' => $chapter->fillBlankWorksheet?->set_code,
            'has_fill_blank_published' => $chapter->fillBlankWorksheetIds() !== [],
            'is_mentormaths' => $book?->isMentorMathsPracticeLine() ?? false,
            'source_ref' => $book?->source_ref,
            'guessed_source_ref' => $guessedRef,
            'rebranded' => $book?->isMentorMathsPracticeLine() ?? false,
            'queue_step' => $this->queueStep($chapter),
        ];
    }

    public function queueStep(TextbookChapter $chapter): string
    {
        $chapter->loadMissing('textbook');

        if (! ($chapter->textbook?->isMentorMathsPracticeLine() ?? false)) {
            return 'rebrand';
        }

        $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];
        if ($items === []) {
            return 'import';
        }

        $fillReady = $this->fillBlankReadyCount($chapter);

        // Keep transforming until the chapter reaches the publish minimum.
        if ($fillReady < self::MIN_FILL_BLANK_READY) {
            return 'transform';
        }

        if ($chapter->fillBlankWorksheetIds() === []) {
            return 'publish';
        }

        return 'done';
    }

    /**
     * @return array{name: string, code: string}
     */
    public function suggestMentorMathsIdentity(int $gradeLevelId): array
    {
        $existing = Textbook::query()
            ->where('grade_level_id', $gradeLevelId)
            ->where(function ($q) {
                $q->where('practice_line', Textbook::PRACTICE_LINE_MENTORMATHS)
                    ->orWhere('name', 'like', 'MentorMaths%')
                    ->orWhere('code', 'like', 'mm%');
            })
            ->get(['name', 'code']);

        $used = [];
        foreach ($existing as $book) {
            if (preg_match('/(\d+)/', (string) $book->code, $m) || preg_match('/(\d+)/', (string) $book->name, $m)) {
                $used[(int) $m[1]] = true;
            }
        }

        $n = 1;
        while (isset($used[$n])) {
            $n++;
        }

        return [
            'name' => "MentorMaths {$n}",
            'code' => 'mm'.$n,
        ];
    }

    /**
     * Rebrand a publisher textbook onto the MentorMaths practice line.
     */
    public function rebrandTextbook(
        Textbook $textbook,
        string $bookName,
        string $bookCode,
        string $sourceRef,
    ): Textbook {
        if (! $this->isConversionCandidate($textbook) && ! $textbook->isMentorMathsPracticeLine()) {
            throw ValidationException::withMessages([
                'textbook' => 'This book is not on the MentorMaths conversion queue.',
            ]);
        }

        if (! MentorMathsSourceRef::isValid($sourceRef) || trim($sourceRef) === '') {
            throw ValidationException::withMessages([
                'source_ref' => 'Choose an internal source reference from the list.',
            ]);
        }

        $name = trim($bookName);
        $code = strtolower(trim($bookCode));

        if ($name === '' || $code === '') {
            throw ValidationException::withMessages([
                'book_name' => 'Enter MentorMaths book name and code.',
            ]);
        }

        $clash = Textbook::query()
            ->where('grade_level_id', $textbook->grade_level_id)
            ->where('code', $code)
            ->where('id', '!=', $textbook->id)
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'book_code' => "Book code {$code} is already used for this class.",
            ]);
        }

        $textbook->update([
            'name' => $name,
            'code' => $code,
            'practice_line' => Textbook::PRACTICE_LINE_MENTORMATHS,
            'source_ref' => trim($sourceRef),
        ]);

        return $textbook->fresh(['gradeLevel']);
    }

    private function classNumber(?string $gradeName): ?int
    {
        if ($gradeName && preg_match('/(\d+)/', $gradeName, $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
