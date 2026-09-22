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
        $title = trim((string) ($match['title'] ?? ''));
        $chapterNumber = $match['chapter_number'] ?? null;
        $syllabusName = trim((string) ($match['syllabus_chapter_name'] ?? ''));
        $sourceRef = trim((string) ($match['source_ref'] ?? ''));

        if ($bookCode === '') {
            throw new InvalidArgumentException('Pack match.book_code is required.');
        }

        $books = Textbook::query()
            ->with('gradeLevel')
            ->whereRaw('LOWER(code) = ?', [$bookCode])
            ->when($sourceRef !== '', fn ($q) => $q->where('source_ref', $sourceRef))
            ->get();

        if ($books->isEmpty()) {
            $books = Textbook::query()
                ->with('gradeLevel')
                ->whereRaw('LOWER(code) = ?', [$bookCode])
                ->get();
        }

        if ($books->isEmpty()) {
            throw new InvalidArgumentException("No textbook with code {$bookCode} found on this server.");
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

        if ($filtered->count() === 1) {
            return $filtered->first();
        }

        if ($filtered->count() > 1) {
            // Narrow by exact title if possible.
            $exact = $filtered->first(fn (TextbookChapter $c) => $this->titlesMatch($title, (string) $c->title));
            if ($exact) {
                return $exact;
            }

            throw new InvalidArgumentException(
                'Multiple chapters matched '
                .($title !== '' ? $title : "ch {$chapterNumber}")
                .' on book '.$bookCode
                .'. Rename/align titles on prod, or import one chapter at a time.'
            );
        }

        throw new InvalidArgumentException(
            'No matching chapter for '
            .($title !== '' ? $title : "chapter {$chapterNumber}")
            .' on book '.$bookCode
            .'. Upload/import the source chapter on prod first, then import this pack.'
        );
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
