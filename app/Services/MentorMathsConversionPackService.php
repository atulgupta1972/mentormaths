<?php

namespace App\Services;

use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class MentorMathsConversionPackService
{
    public const FORMAT = 'mentormaths_conversion_pack';

    public const VERSION = 1;

    public function __construct(
        private MentorMathsConversionQueueService $queue,
        private TextbookChapterPublishService $publishService,
    ) {}

    /**
     * @param  list<int>  $chapterIds
     * @return array<string, mixed>
     */
    public function buildPack(array $chapterIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $chapterIds)));
        if ($ids === []) {
            throw new InvalidArgumentException('Select at least one chapter to export.');
        }

        $chapters = TextbookChapter::query()
            ->with(['textbook.gradeLevel', 'syllabusChapter'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $missing = array_values(array_diff($ids, $chapters->keys()->all()));
        if ($missing !== []) {
            throw new InvalidArgumentException('Chapter id(s) not found: '.implode(', ', $missing));
        }

        $entries = [];
        foreach ($ids as $id) {
            /** @var TextbookChapter $chapter */
            $chapter = $chapters->get($id);
            $entries[] = $this->exportChapter($chapter);
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'chapter_count' => count($entries),
            'chapters' => $entries,
        ];
    }

    /**
     * @return array{path: string, absolute_path: string, pack: array<string, mixed>}
     */
    public function writePackFile(array $chapterIds, ?string $relativePath = null): array
    {
        $pack = $this->buildPack($chapterIds);
        $relativePath ??= 'mentormaths-packs/mm-conversion-'.now()->format('Ymd-His').'.json';

        Storage::disk('local')->put(
            $relativePath,
            json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        return [
            'path' => $relativePath,
            'absolute_path' => Storage::disk('local')->path($relativePath),
            'pack' => $pack,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function exportChapter(TextbookChapter $chapter): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);
        $book = $chapter->textbook;
        $items = is_array($chapter->extraction_items) ? array_values($chapter->extraction_items) : [];
        $ready = $this->queue->fillBlankReadyCount($chapter);

        return [
            'match' => [
                'local_chapter_id' => $chapter->id,
                'book_code' => $book?->code,
                'book_name' => $book?->name,
                'source_ref' => $book?->source_ref,
                'grade_name' => $book?->gradeLevel?->name,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'syllabus_chapter_name' => $chapter->syllabusChapter?->name,
                'syllabus_chapter_number' => $chapter->syllabusChapter?->chapter_number,
            ],
            'fill_blank_ready_count' => $ready,
            'items_count' => count($items),
            'extraction_items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $pack
     * @return array{imported: list<array<string, mixed>>, errors: list<string>}
     */
    public function importPack(array $pack, User $publisher, bool $publish = true): array
    {
        $this->assertPack($pack);

        $imported = [];
        $errors = [];

        foreach ($pack['chapters'] as $index => $entry) {
            if (! is_array($entry)) {
                $errors[] = 'Chapter entry #'.($index + 1).' is invalid.';
                continue;
            }

            try {
                $imported[] = $this->importChapterEntry($entry, $publisher, $publish);
            } catch (\Throwable $e) {
                $label = $entry['match']['title'] ?? ('entry #'.($index + 1));
                $errors[] = "{$label}: ".$e->getMessage();
            }
        }

        return compact('imported', 'errors');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public function importChapterEntry(array $entry, User $publisher, bool $publish = true): array
    {
        $match = is_array($entry['match'] ?? null) ? $entry['match'] : [];
        $items = $entry['extraction_items'] ?? null;

        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('Pack chapter has no extraction_items.');
        }

        $chapter = $this->resolveTargetChapter($match);
        $chapter->loadMissing('textbook');

        if (! ($chapter->textbook?->isMentorMathsPracticeLine() ?? false)) {
            throw new InvalidArgumentException(
                'Target book is not on the MentorMaths practice line. Rebrand it first, then import.'
            );
        }

        $existing = is_array($chapter->extraction_items) ? array_values($chapter->extraction_items) : [];
        if ($existing === []) {
            // Prod never imported source questions — use the pack rows as-is.
            $merged = array_values(array_filter($items, fn ($row) => is_array($row)));
        } else {
            $merged = $this->mergeFillBlankFields($existing, array_values($items));
        }

        $chapter->update(['extraction_items' => $merged]);
        $chapter = $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter']);

        $ready = $this->queue->fillBlankReadyCount($chapter);
        $published = false;
        $worksheetIds = $chapter->fillBlankWorksheetIds();

        if ($publish) {
            if ($ready < MentorMathsConversionQueueService::MIN_FILL_BLANK_READY) {
                return [
                    'local_chapter_id' => $match['local_chapter_id'] ?? null,
                    'target_chapter_id' => $chapter->id,
                    'title' => $chapter->title,
                    'book_code' => $chapter->textbook?->code,
                    'fill_blank_ready_count' => $ready,
                    'published' => false,
                    'fill_blank_worksheet_ids' => $worksheetIds,
                    'warning' => "Saved fill-blanks ({$ready} ready) but publish needs "
                        .MentorMathsConversionQueueService::MIN_FILL_BLANK_READY.'+.',
                ];
            }

            $chapter = $this->publishService->publishFillBlankAndWritten($chapter, $publisher);
            $published = true;
            $worksheetIds = $chapter->fillBlankWorksheetIds();
        }

        return [
            'local_chapter_id' => $match['local_chapter_id'] ?? null,
            'target_chapter_id' => $chapter->id,
            'title' => $chapter->title,
            'book_code' => $chapter->textbook?->code,
            'fill_blank_ready_count' => $ready,
            'published' => $published,
            'fill_blank_worksheet_ids' => $worksheetIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $match
     */
    public function resolveTargetChapter(array $match): TextbookChapter
    {
        $bookCode = strtolower(trim((string) ($match['book_code'] ?? '')));
        $bookName = trim((string) ($match['book_name'] ?? ''));
        $title = trim((string) ($match['title'] ?? ''));
        $chapterNumber = $match['chapter_number'] ?? null;
        $syllabusName = trim((string) ($match['syllabus_chapter_name'] ?? ''));
        $sourceRef = trim((string) ($match['source_ref'] ?? ''));
        $gradeName = trim((string) ($match['grade_name'] ?? ''));

        $books = $this->resolveTargetBooks($match);

        if ($books->isEmpty()) {
            $hint = $this->availableBookHint($gradeName);
            throw new InvalidArgumentException(
                "No textbook matched code {$bookCode}"
                .($bookName !== '' ? " / name \"{$bookName}\"" : '')
                .($sourceRef !== '' ? " / source {$sourceRef}" : '')
                .' on this server.'
                .($hint !== '' ? " Available: {$hint}. Rebrand the Class 7 book to MentorMaths (code mm2) first, then re-run import." : '')
            );
        }

        $bookIds = $books->pluck('id')->all();

        $candidates = TextbookChapter::query()
            ->with(['textbook', 'syllabusChapter'])
            ->whereIn('textbook_id', $bookIds)
            ->get();

        $filtered = $candidates->filter(function (TextbookChapter $chapter) use ($title, $chapterNumber, $syllabusName) {
            $numberOk = $chapterNumber === null || $chapterNumber === ''
                || (string) $chapter->chapter_number === (string) $chapterNumber;

            $titleOk = $title === '' || $this->titlesMatch($title, (string) $chapter->title)
                || $this->titlesMatch($title, (string) ($chapter->syllabusChapter?->name ?? ''));

            $syllabusOk = $syllabusName === ''
                || $this->titlesMatch($syllabusName, (string) ($chapter->syllabusChapter?->name ?? ''))
                || $this->titlesMatch($syllabusName, (string) $chapter->title);

            // Prefer chapters that match number + (title or syllabus).
            return $numberOk && ($titleOk || $syllabusOk || ($title === '' && $syllabusName === ''));
        })->values();

        // If number+title failed (prod chapter numbers differ), retry by title/syllabus only.
        if ($filtered->isEmpty() && ($title !== '' || $syllabusName !== '')) {
            $filtered = $candidates->filter(function (TextbookChapter $chapter) use ($title, $syllabusName) {
                return ($title !== '' && (
                    $this->titlesMatch($title, (string) $chapter->title)
                    || $this->titlesMatch($title, (string) ($chapter->syllabusChapter?->name ?? ''))
                )) || ($syllabusName !== '' && (
                    $this->titlesMatch($syllabusName, (string) ($chapter->syllabusChapter?->name ?? ''))
                    || $this->titlesMatch($syllabusName, (string) $chapter->title)
                ));
            })->values();
        }

        if ($filtered->count() === 1) {
            return $filtered->first();
        }

        if ($filtered->count() > 1) {
            // Narrow by exact title if possible.
            $exact = $filtered->first(fn (TextbookChapter $c) => $this->titlesMatch($title, (string) $c->title));
            if ($exact) {
                return $exact;
            }

            $bookLabel = $books->map(fn (Textbook $b) => $b->code)->unique()->implode('/');
            throw new InvalidArgumentException(
                'Multiple chapters matched '
                .($title !== '' ? $title : "ch {$chapterNumber}")
                .' on book '.$bookLabel
                .'. Rename/align titles on prod, or import one chapter at a time.'
            );
        }

        $bookLabel = $books->map(fn (Textbook $b) => $b->code.' ('.$b->name.')')->unique()->implode(', ');
        throw new InvalidArgumentException(
            'No matching chapter for '
            .($title !== '' ? $title : "chapter {$chapterNumber}")
            .' under '.$bookLabel
            .'. Upload/import that source chapter on prod first (or rebrand the publisher book to MentorMaths), then import this pack.'
        );
    }

    /**
     * @param  array<string, mixed>  $match
     * @return \Illuminate\Support\Collection<int, Textbook>
     */
    private function resolveTargetBooks(array $match)
    {
        $bookCode = strtolower(trim((string) ($match['book_code'] ?? '')));
        $bookName = trim((string) ($match['book_name'] ?? ''));
        $sourceRef = trim((string) ($match['source_ref'] ?? ''));
        $gradeName = trim((string) ($match['grade_name'] ?? ''));

        $query = Textbook::query()->with('gradeLevel');

        // 1) Exact code (mm2)
        if ($bookCode !== '') {
            $books = (clone $query)->whereRaw('LOWER(code) = ?', [$bookCode])->get();
            if ($books->isNotEmpty()) {
                return $books;
            }
        }

        // 2) MentorMaths practice line + source_ref (RDS-C7)
        if ($sourceRef !== '') {
            $books = (clone $query)
                ->where('practice_line', Textbook::PRACTICE_LINE_MENTORMATHS)
                ->where('source_ref', $sourceRef)
                ->get();
            if ($books->isNotEmpty()) {
                return $books;
            }
        }

        // 3) Book name (MentorMaths 2)
        if ($bookName !== '') {
            $books = (clone $query)->get()->filter(
                fn (Textbook $book) => $this->titlesMatch($bookName, (string) $book->name)
            )->values();
            if ($books->isNotEmpty()) {
                return $books;
            }
        }

        // 4) Mentormaths line + same class
        if ($gradeName !== '') {
            $books = (clone $query)
                ->where('practice_line', Textbook::PRACTICE_LINE_MENTORMATHS)
                ->whereHas('gradeLevel', fn ($q) => $q->where('name', $gradeName))
                ->get();
            if ($books->count() === 1) {
                return $books;
            }
        }

        // 5) Publisher conversion book on that class with matching source_ref or RDS-like code
        if ($gradeName !== '' || $sourceRef !== '') {
            $books = (clone $query)
                ->when($gradeName !== '', fn ($q) => $q->whereHas(
                    'gradeLevel',
                    fn ($inner) => $inner->where('name', $gradeName),
                ))
                ->get()
                ->filter(function (Textbook $book) use ($sourceRef) {
                    if (! app(MentorMathsConversionQueueService::class)->isConversionCandidate($book)) {
                        return false;
                    }
                    if ($sourceRef !== '' && (string) $book->source_ref === $sourceRef) {
                        return true;
                    }

                    $code = strtolower((string) $book->code);

                    return in_array($code, ['rds', 'rs', 'mm2', 'mm1'], true)
                        || str_starts_with($code, 'mm');
                })
                ->values();

            if ($books->isNotEmpty()) {
                return $books;
            }
        }

        return collect();
    }

    private function availableBookHint(string $gradeName): string
    {
        $books = Textbook::query()
            ->with('gradeLevel:id,name')
            ->when($gradeName !== '', fn ($q) => $q->whereHas(
                'gradeLevel',
                fn ($inner) => $inner->where('name', $gradeName),
            ))
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'code', 'practice_line', 'source_ref', 'grade_level_id']);

        if ($books->isEmpty()) {
            $books = Textbook::query()->orderBy('name')->limit(12)->get(['id', 'name', 'code', 'practice_line', 'source_ref']);
        }

        return $books
            ->map(function (Textbook $book) {
                $bits = [$book->code, $book->name];
                if ($book->source_ref) {
                    $bits[] = $book->source_ref;
                }
                if ($book->practice_line) {
                    $bits[] = $book->practice_line;
                }

                return implode(' · ', $bits);
            })
            ->implode(' | ');
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $packItems
     * @return list<array<string, mixed>>
     */
    private function mergeFillBlankFields(array $existing, array $packItems): array
    {
        if (count($packItems) !== count($existing)) {
            // Still allow if pack is longer (extra invented rows appended) — merge by index for overlap.
            // If pack is shorter, only update overlapping indexes.
        }

        $limit = min(count($existing), count($packItems));
        $fillKeys = [
            'fill_blank_question_text',
            'fill_blank_correct_answer',
            'fill_blank_answer_format',
            'fill_blank_decimal_places',
            'fill_blank_method_hint',
            'fill_blank_explanation',
            'include_in_fill_blank',
            'include_in_written',
            'fill_blank_skipped',
            'fill_blank_imported_at',
            'fill_blank_gemini_ready',
            'fill_blank_checked_at',
            'fill_blank_checked_hash',
            'fill_blank_transformed',
            'fill_blank_similarity_overlap',
            'diagram_staging_path',
            'needs_diagram',
        ];

        for ($i = 0; $i < $limit; $i++) {
            if (! is_array($packItems[$i])) {
                continue;
            }

            foreach ($fillKeys as $key) {
                if (array_key_exists($key, $packItems[$i])) {
                    $existing[$i][$key] = $packItems[$i][$key];
                }
            }

            // Keep topic/difficulty hints from the converted row when present.
            foreach (['topic', 'difficulty', 'label'] as $meta) {
                if (filled($packItems[$i][$meta] ?? null) && ! filled($existing[$i][$meta] ?? null)) {
                    $existing[$i][$meta] = $packItems[$i][$meta];
                }
            }
        }

        // If pack has extra invented-only rows beyond source MCQs, append them.
        if (count($packItems) > count($existing)) {
            for ($i = count($existing); $i < count($packItems); $i++) {
                if (is_array($packItems[$i])) {
                    $existing[] = $packItems[$i];
                }
            }
        }

        return array_values($existing);
    }

    /**
     * @param  array<string, mixed>  $pack
     */
    private function assertPack(array $pack): void
    {
        if (($pack['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Not a MentorMaths conversion pack (bad format).');
        }

        if ((int) ($pack['version'] ?? 0) !== self::VERSION) {
            throw new InvalidArgumentException('Unsupported conversion pack version.');
        }

        if (! is_array($pack['chapters'] ?? null) || $pack['chapters'] === []) {
            throw new InvalidArgumentException('Pack has no chapters.');
        }
    }

    private function titlesMatch(string $left, string $right): bool
    {
        return $this->normalizeTitle($left) !== ''
            && $this->normalizeTitle($left) === $this->normalizeTitle($right);
    }

    private function normalizeTitle(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '', $value) ?? '';

        return $value;
    }

    /**
     * Done MentorMaths chapters for the optional one-shot export.
     *
     * @return Collection<int, TextbookChapter>
     */
    public function doneChapters(?string $bookCode = null): Collection
    {
        return TextbookChapter::query()
            ->with(['textbook.gradeLevel', 'syllabusChapter'])
            ->whereHas('textbook', function ($q) use ($bookCode) {
                $q->where('practice_line', Textbook::PRACTICE_LINE_MENTORMATHS);
                if ($bookCode) {
                    $q->whereRaw('LOWER(code) = ?', [strtolower($bookCode)]);
                }
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (TextbookChapter $chapter) => $this->queue->chapterIsDone($chapter))
            ->values();
    }
}
