<?php

namespace App\Services;

use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
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

    public function chapterIsDone(TextbookChapter $chapter): bool
    {
        $chapter->loadMissing('textbook');

        $book = $chapter->textbook;
        if (! $book || ! $this->isConversionCandidate($book)) {
            return false;
        }

        return $book->isMentorMathsPracticeLine() && $chapter->fillBlankWorksheetIds() !== [];
    }

    public function chapterIsPending(TextbookChapter $chapter): bool
    {
        $chapter->loadMissing('textbook');

        $book = $chapter->textbook;
        if (! $book || ! $this->isConversionCandidate($book)) {
            return false;
        }

        return ! $this->chapterIsDone($chapter);
    }

    /**
     * @return array{
     *     chapters: list<array<string, mixed>>,
     *     done_chapters: list<array<string, mixed>>,
     *     books: list<array<string, mixed>>,
     *     grades: list<array{id: int, name: string}>,
     *     filters: array{grade_level_id: ?int, textbook_id: ?int},
     *     pending_count: int,
     *     done_count: int
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

        $allChapters = TextbookChapter::query()
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
            ->get();

        $chapters = $allChapters
            ->filter(fn (TextbookChapter $chapter) => $this->chapterIsPending($chapter))
            ->map(fn (TextbookChapter $chapter) => $this->chapterRow($chapter))
            ->values()
            ->all();

        $doneChapters = $allChapters
            ->filter(fn (TextbookChapter $chapter) => $this->chapterIsDone($chapter))
            ->sortByDesc(fn (TextbookChapter $chapter) => $chapter->published_at?->timestamp ?? $chapter->updated_at?->timestamp ?? 0)
            ->map(fn (TextbookChapter $chapter) => $this->chapterRow($chapter))
            ->values()
            ->all();

        return [
            'chapters' => $chapters,
            'done_chapters' => $doneChapters,
            'books' => $booksForFilter,
            'grades' => $grades,
            'filters' => [
                'grade_level_id' => $gradeLevelId,
                'textbook_id' => $textbookId,
            ],
            'pending_count' => count($chapters),
            'done_count' => count($doneChapters),
            'min_fill_blank_ready' => self::MIN_FILL_BLANK_READY,
            'next_chapter' => $chapters[0] ?? null,
        ];
    }

    /**
     * Lightweight summary for Dashboard / nav CTAs.
     * Must not run publish-blocker scans or full chapterRow mapping.
     *
     * @return array{pending_count: int, next_chapter_id: ?int, next_chapter_label: ?string}
     */
    public function summary(?int $gradeLevelId = null): array
    {
        $candidateBookIds = Textbook::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'grade_level_id', 'practice_line', 'source_ref', 'is_active'])
            ->filter(fn (Textbook $book) => $this->isConversionCandidate($book))
            ->when($gradeLevelId, fn (Collection $c) => $c->where('grade_level_id', $gradeLevelId))
            ->pluck('id')
            ->values()
            ->all();

        if ($candidateBookIds === []) {
            return [
                'pending_count' => 0,
                'next_chapter_id' => null,
                'next_chapter_label' => null,
            ];
        }

        $pending = TextbookChapter::query()
            ->with([
                'textbook:id,name,code,grade_level_id,practice_line,source_ref',
                'textbook.gradeLevel:id,name',
                'syllabusChapter:id,name,chapter_number',
            ])
            ->whereIn('textbook_id', $candidateBookIds)
            ->orderBy('textbook_id')
            ->orderBy('chapter_number')
            ->get()
            ->filter(fn (TextbookChapter $chapter) => $this->chapterIsPending($chapter))
            ->values();

        $next = $pending->first();
        if (! $next) {
            return [
                'pending_count' => 0,
                'next_chapter_id' => null,
                'next_chapter_label' => null,
            ];
        }

        $next->syncDisplayFromSyllabus();

        return [
            'pending_count' => $pending->count(),
            'next_chapter_id' => (int) $next->id,
            'next_chapter_label' => trim(
                ($next->textbook?->gradeLevel?->name ?? '').' · '.$next->displaySyllabusLabel()
            ),
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
        $publishBlockers = app(GeminiFillBlankConversionService::class)->publishBlockers($chapter);

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
            'publish_blocker_count' => count($publishBlockers),
            'publish_blockers' => collect($publishBlockers)->map(fn (array $row) => [
                'index' => $row['index'],
                'number' => $row['number'],
                'label' => $row['label'],
                'reason' => $row['reason'],
                'overlap' => $row['overlap'] ?? null,
            ])->values()->all(),
            'fill_blank_set_code' => $chapter->fillBlankWorksheet?->set_code,
            'fill_blank_worksheet_count' => count($chapter->fillBlankWorksheetIds()),
            'has_fill_blank_published' => $chapter->fillBlankWorksheetIds() !== [],
            'published_at' => $chapter->published_at?->toDateString(),
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

        if ($this->publishBlockerCount($chapter) > 0) {
            return 'transform';
        }

        if ($chapter->fillBlankWorksheetIds() === []) {
            return 'publish';
        }

        return 'done';
    }

    public function publishBlockerCount(TextbookChapter $chapter): int
    {
        return count(app(GeminiFillBlankConversionService::class)->publishBlockers($chapter));
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
     * Full sum text for every pending chapter in the current filter.
     *
     * @return array{chapters: list<array<string, mixed>>, filters: array{grade_level_id: ?int, textbook_id: ?int}, book_name: ?string}
     */
    public function reviewPayload(?int $gradeLevelId = null, ?int $textbookId = null): array
    {
        $queue = $this->queue($gradeLevelId, $textbookId);
        $ids = collect($queue['chapters'])->pluck('id')->all();
        $models = TextbookChapter::query()
            ->whereIn('id', $ids !== [] ? $ids : [0])
            ->get()
            ->keyBy('id');

        $chapters = [];
        foreach ($queue['chapters'] as $row) {
            $model = $models->get($row['id']);
            $items = is_array($model?->extraction_items) ? $model->extraction_items : [];
            $sums = [];
            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $fill = trim((string) ($item['fill_blank_question_text'] ?? ''));
                $mcq = trim((string) ($item['question_text'] ?? ''));
                $sums[] = [
                    'index' => $index,
                    'number' => $index + 1,
                    'text' => $fill !== '' ? $fill : $mcq,
                    'field' => $fill !== '' ? 'fill_blank' : 'question',
                    'will_publish' => $fill !== '' && filled($item['fill_blank_correct_answer'] ?? null),
                ];
            }

            $chapters[] = [
                'id' => $row['id'],
                'label' => $row['label'],
                'book_name' => $row['book_name'],
                'grade_name' => $row['grade_name'],
                'is_mentormaths' => $row['is_mentormaths'],
                'meets_publish_minimum' => $row['meets_publish_minimum'],
                'fill_blank_ready_count' => $row['fill_blank_ready_count'],
                'sums' => $sums,
            ];
        }

        return [
            'chapters' => $chapters,
            'filters' => $queue['filters'],
            'book_name' => $chapters[0]['book_name'] ?? null,
        ];
    }

    /**
     * @return array{chapter: array<string, mixed>, current_book_name: string, old_book_name: ?string}
     */
    public function chapterSumsPayload(TextbookChapter $chapter): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);
        $row = $this->chapterRow($chapter);

        return [
            'chapter' => [
                'id' => $row['id'],
                'label' => $row['label'],
                'grade_name' => $row['grade_name'],
                'book_name' => $row['book_name'],
                'items_count' => $row['items_count'],
            ],
            'current_book_name' => (string) ($row['book_name'] ?? ''),
            'old_book_name' => $this->publisherNameFromSourceRef((string) ($chapter->textbook?->source_ref ?? '')),
        ];
    }

    /**
     * Put this chapter's current book name on its stored sums and on questions already published from it.
     *
     * @return array{places: int, message: string}
     */
    public function applyCurrentBookNameEverywhere(TextbookChapter $chapter): array
    {
        $chapter->loadMissing('textbook');
        $to = trim((string) ($chapter->textbook?->name ?? ''));
        if ($to === '') {
            throw ValidationException::withMessages([
                'book_name' => 'This chapter has no book name to apply.',
            ]);
        }

        $from = $this->publisherNameFromSourceRef((string) ($chapter->textbook?->source_ref ?? ''));
        $needles = $from !== null ? $this->bookNameNeedles($from) : $this->allPublisherNeedles();
        $needles = array_values(array_filter(
            $needles,
            fn (string $needle) => mb_strtolower($needle) !== mb_strtolower($to),
        ));

        $places = 0;
        $places += $this->replaceNeedlesInExtraction($chapter, $needles, $to);
        $places += $this->replaceNeedlesInPublishedQuestions($chapter, $needles, $to);

        $old = $from ?? 'the old book name';

        return [
            'places' => $places,
            'message' => $places > 0
                ? "Changed {$places} place(s) in this chapter to {$to}."
                : "{$to} is already the book name for this chapter everywhere.",
        ];
    }

    /**
     * Replace a chosen book name with this chapter's current book name.
     */
    public function replaceBookNameInChapter(TextbookChapter $chapter, string $fromName): int
    {
        $chapter->loadMissing('textbook');
        $from = trim($fromName);
        $to = trim((string) ($chapter->textbook?->name ?? ''));

        if (mb_strlen($from) < 2) {
            throw ValidationException::withMessages([
                'book_name' => 'Choose a book name to replace.',
            ]);
        }

        if ($to === '' || mb_strtolower($from) === mb_strtolower($to)) {
            throw ValidationException::withMessages([
                'book_name' => 'Pick a different book name. The current book name stays as it is.',
            ]);
        }

        $needles = $this->bookNameNeedles($from);
        $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];
        $replaced = 0;
        $changed = false;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['question_text', 'fill_blank_question_text'] as $field) {
                $text = (string) ($item[$field] ?? '');
                if ($text === '') {
                    continue;
                }
                $next = $this->swapBookNames($text, $needles, $to, $count);
                if ($count > 0) {
                    $items[$index][$field] = $next;
                    $replaced += $count;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $chapter->update(['extraction_items' => array_values($items)]);
        }

        return $replaced;
    }

    private function publisherNameFromSourceRef(string $ref): ?string
    {
        return match (true) {
            str_starts_with($ref, 'RDS-') => 'RD Sharma',
            str_starts_with($ref, 'RSA-') => 'RS Aggarwal',
            str_starts_with($ref, 'GL-') => 'Greya Lakshmi',
            str_starts_with($ref, 'EXEM-') => 'NCERT Exemplar',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function allPublisherNeedles(): array
    {
        $needles = [];
        foreach (['RD Sharma', 'RS Aggarwal', 'Greya Lakshmi', 'NCERT Exemplar'] as $name) {
            $needles = array_merge($needles, $this->bookNameNeedles($name));
        }

        return array_values(array_unique($needles));
    }

    /**
     * @param  list<string>  $needles
     */
    private function replaceNeedlesInExtraction(TextbookChapter $chapter, array $needles, string $to): int
    {
        if ($needles === []) {
            return 0;
        }

        $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];
        $replaced = 0;
        $changed = false;

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            foreach (['question_text', 'fill_blank_question_text'] as $field) {
                $text = (string) ($item[$field] ?? '');
                if ($text === '') {
                    continue;
                }
                $next = $this->swapBookNames($text, $needles, $to, $count);
                if ($count > 0) {
                    $items[$index][$field] = $next;
                    $replaced += $count;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $chapter->update(['extraction_items' => array_values($items)]);
        }

        return $replaced;
    }

    /**
     * @param  list<string>  $needles
     */
    private function replaceNeedlesInPublishedQuestions(TextbookChapter $chapter, array $needles, string $to): int
    {
        if ($needles === []) {
            return 0;
        }

        $worksheetIds = $chapter->allWorksheetIds();
        $replaced = 0;

        if ($worksheetIds !== []) {
            Question::query()
                ->whereHas('worksheets', fn ($query) => $query->whereIn('worksheets.id', $worksheetIds))
                ->with('options')
                ->orderBy('id')
                ->each(function (Question $question) use ($needles, $to, &$replaced) {
                    foreach (['question_text', 'explanation', 'method_hint'] as $field) {
                        $text = (string) ($question->{$field} ?? '');
                        if ($text === '') {
                            continue;
                        }
                        $next = $this->swapBookNames($text, $needles, $to, $count);
                        if ($count > 0) {
                            $question->{$field} = $next;
                            $replaced += $count;
                        }
                    }
                    if ($question->isDirty()) {
                        $question->save();
                    }
                    foreach ($question->options as $option) {
                        $text = (string) ($option->option_text ?? '');
                        if ($text === '') {
                            continue;
                        }
                        $next = $this->swapBookNames($text, $needles, $to, $count);
                        if ($count > 0) {
                            $option->option_text = $next;
                            $option->save();
                            $replaced += $count;
                        }
                    }
                });

            Worksheet::query()
                ->whereIn('id', $worksheetIds)
                ->orderBy('id')
                ->each(function (Worksheet $worksheet) use ($needles, $to, &$replaced) {
                    foreach (['title', 'notes'] as $field) {
                        $text = (string) ($worksheet->{$field} ?? '');
                        if ($text === '') {
                            continue;
                        }
                        $next = $this->swapBookNames($text, $needles, $to, $count);
                        if ($count > 0) {
                            $worksheet->{$field} = $next;
                            $replaced += $count;
                        }
                    }
                    if ($worksheet->isDirty()) {
                        $worksheet->save();
                    }
                });
        }

        return $replaced;
    }

    /**
     * @return list<string>
     */
    private function bookNameNeedles(string $name): array
    {
        $needles = [trim($name)];
        $lower = mb_strtolower($name);

        if (str_contains($lower, 'aggarwal') || str_contains($lower, 'agarwal')) {
            $needles = array_merge($needles, [
                'RS Aggarwal', 'R.S. Aggarwal', 'R. S. Aggarwal', 'R S Aggarwal',
                'RS Agarwal', 'R.S. Agarwal', 'R. S. Agarwal', 'R S Agarwal',
            ]);
        }
        if (str_contains($lower, 'sharma')) {
            $needles = array_merge($needles, [
                'RD Sharma', 'R.D. Sharma', 'R. D. Sharma', 'R D Sharma',
            ]);
        }
        if (str_contains($lower, 'exemplar')) {
            $needles[] = 'NCERT Exemplar';
        }
        if (str_contains($lower, 'lakshmi')) {
            $needles[] = 'Greya Lakshmi';
        }

        $needles = array_values(array_unique(array_filter($needles, fn (string $needle) => mb_strlen(trim($needle)) >= 2)));
        usort($needles, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $needles;
    }

    /**
     * @param  list<string>  $needles
     */
    private function swapBookNames(string $text, array $needles, string $to, ?int &$count = null): string
    {
        $count = 0;
        $next = $text;
        foreach ($needles as $needle) {
            $found = 0;
            $replaced = str_ireplace($needle, $to, $next, $found);
            if ($found > 0) {
                $next = $replaced;
                $count += $found;
            }
        }

        return $next;
    }

    /**
     * Replace a phrase (old book name or a person's name) in pending sum text.
     */
    public function replaceInPendingSums(?int $gradeLevelId, ?int $textbookId, string $find, string $replace): int
    {
        $find = trim($find);
        if (mb_strlen($find) < 2) {
            throw ValidationException::withMessages([
                'find' => 'Type at least 2 characters to find (a book name or a person name).',
            ]);
        }

        $payload = $this->reviewPayload($gradeLevelId, $textbookId);
        $ids = collect($payload['chapters'])->pluck('id')->all();
        $replaced = 0;

        TextbookChapter::query()
            ->whereIn('id', $ids !== [] ? $ids : [0])
            ->orderBy('id')
            ->each(function (TextbookChapter $chapter) use ($find, $replace, &$replaced) {
                $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];
                $changed = false;

                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    foreach (['question_text', 'fill_blank_question_text'] as $field) {
                        $text = (string) ($item[$field] ?? '');
                        if ($text === '' || ! str_contains($text, $find)) {
                            continue;
                        }
                        $next = str_replace($find, $replace, $text, $count);
                        $replaced += $count;
                        $items[$index][$field] = $next;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $chapter->update(['extraction_items' => array_values($items)]);
                }
            });

        return $replaced;
    }

    /**
     * @param  list<array{index: int, text: string, field: string}>  $sums
     */
    public function saveSumTexts(TextbookChapter $chapter, array $sums): int
    {
        $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];
        $saved = 0;

        foreach ($sums as $sum) {
            $index = (int) ($sum['index'] ?? -1);
            if (! isset($items[$index]) || ! is_array($items[$index])) {
                continue;
            }
            $field = ($sum['field'] ?? '') === 'fill_blank' ? 'fill_blank_question_text' : 'question_text';
            $items[$index][$field] = trim((string) ($sum['text'] ?? ''));
            $saved++;
        }

        if ($saved > 0) {
            $chapter->update(['extraction_items' => array_values($items)]);
        }

        return $saved;
    }

    /**
     * @return list<TextbookChapter>
     */
    public function pendingChapterModels(?int $gradeLevelId, ?int $textbookId): array
    {
        $ids = collect($this->queue($gradeLevelId, $textbookId)['chapters'])->pluck('id')->all();

        if ($ids === []) {
            return [];
        }

        return TextbookChapter::query()
            ->with(['textbook', 'syllabusChapter'])
            ->whereIn('id', $ids)
            ->orderBy('textbook_id')
            ->orderBy('chapter_number')
            ->get()
            ->all();
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
