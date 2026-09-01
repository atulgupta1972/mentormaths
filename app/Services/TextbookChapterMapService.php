<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\TextbookChapterMap;
use Illuminate\Support\Collection;

class TextbookChapterMapService
{
    /**
     * @param  list<array{book_chapter_number: string, book_chapter_title: string, syllabus_chapter_id: int}>  $rows
     * @return Collection<int, TextbookChapterMap>
     */
    public function syncMaps(Textbook $textbook, array $rows, int $userId): Collection
    {
        $rows = collect($rows)
            ->map(function (array $row, int $index) {
                return [
                    'book_chapter_number' => trim((string) ($row['book_chapter_number'] ?? '')),
                    'book_chapter_title' => trim((string) ($row['book_chapter_title'] ?? '')),
                    'syllabus_chapter_id' => (int) ($row['syllabus_chapter_id'] ?? 0),
                    'sort_order' => (int) ($row['sort_order'] ?? ($index + 1)),
                ];
            })
            ->filter(fn (array $row) => $row['syllabus_chapter_id'] > 0
                && $row['book_chapter_number'] !== ''
                && $row['book_chapter_title'] !== '')
            ->values();

        if ($rows->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one book chapter mapped to a syllabus chapter.');
        }

        $syllabusIds = $rows->pluck('syllabus_chapter_id')->unique()->values();
        if ($syllabusIds->count() !== $rows->count()) {
            throw new \InvalidArgumentException('Each syllabus chapter can only be mapped once per book.');
        }

        $bookNumbers = $rows->pluck('book_chapter_number')->unique();
        if ($bookNumbers->count() !== $rows->count()) {
            throw new \InvalidArgumentException('Each book chapter number must be unique within this book.');
        }

        $keptIds = [];

        foreach ($rows as $row) {
            $map = TextbookChapterMap::query()->updateOrCreate(
                [
                    'textbook_id' => $textbook->id,
                    'syllabus_chapter_id' => $row['syllabus_chapter_id'],
                ],
                [
                    'book_chapter_number' => $row['book_chapter_number'],
                    'book_chapter_title' => $row['book_chapter_title'],
                    'sort_order' => $row['sort_order'],
                    'created_by' => $userId,
                ],
            );

            $keptIds[] = $map->id;
        }

        TextbookChapterMap::query()
            ->where('textbook_id', $textbook->id)
            ->whereNotIn('id', $keptIds)
            ->delete();

        return TextbookChapterMap::query()
            ->where('textbook_id', $textbook->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mapsForTextbookAdmin(Textbook $textbook, int $gradeLevelId): array
    {
        $maps = TextbookChapterMap::query()
            ->where('textbook_id', $textbook->id)
            ->with(['syllabusChapter'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $textbookChapters = TextbookChapter::query()
            ->where('textbook_id', $textbook->id)
            ->get()
            ->keyBy('syllabus_chapter_id');

        $tasksByChapter = ContentUploadTask::query()
            ->whereHas('textbookChapter', fn ($q) => $q->where('textbook_id', $textbook->id))
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->with('textbookChapter:id,syllabus_chapter_id')
            ->get()
            ->groupBy(fn ($task) => (int) ($task->textbookChapter?->syllabus_chapter_id ?? 0));

        $rateCardService = app(ContentRateCardService::class);

        return $maps->map(function (TextbookChapterMap $map) use (
            $textbookChapters,
            $tasksByChapter,
            $rateCardService,
            $gradeLevelId,
        ) {
            $syllabus = $map->syllabusChapter;
            $textbookChapter = $textbookChapters->get($map->syllabus_chapter_id);
            $rate = $syllabus
                ? $rateCardService->resolveRateForSyllabusChapter($gradeLevelId, $syllabus)
                : ['amount_inr' => 0, 'rate_basis' => 'per_question'];

            $assigned = $tasksByChapter->has((int) $map->syllabus_chapter_id);
            $uploaded = $textbookChapter && (
                $textbookChapter->status === TextbookChapter::STATUS_PUBLISHED
                || $textbookChapter->mcqWorksheetIds() !== []
            );

            return [
                'id' => $map->id,
                'book_chapter_number' => $map->book_chapter_number,
                'book_chapter_title' => $map->book_chapter_title,
                'book_label' => $map->bookLabel(),
                'syllabus_chapter_id' => $map->syllabus_chapter_id,
                'syllabus_label' => $syllabus
                    ? trim((string) $syllabus->chapter_number).' — '.$syllabus->name
                    : '',
                'default_amount_inr' => $rate['amount_inr'],
                'default_rate_basis' => $rate['rate_basis'],
                'assigned' => $assigned,
                'uploaded' => $uploaded,
                'block_reason' => $uploaded ? 'already uploaded' : ($assigned ? 'already assigned' : ''),
            ];
        })->all();
    }

    public function textbookChapterFromMap(TextbookChapterMap $map, int $userId): TextbookChapter
    {
        $textbookChapter = TextbookChapter::query()->firstOrCreate(
            [
                'textbook_id' => $map->textbook_id,
                'syllabus_chapter_id' => $map->syllabus_chapter_id,
            ],
            [
                'chapter_number' => $map->book_chapter_number,
                'title' => $map->book_chapter_title,
                'pdf_path' => null,
                'status' => TextbookChapter::STATUS_DRAFT,
                'created_by' => $userId,
            ],
        );

        if ($textbookChapter->chapter_number !== $map->book_chapter_number
            || $textbookChapter->title !== $map->book_chapter_title) {
            $textbookChapter->update([
                'chapter_number' => $map->book_chapter_number,
                'title' => $map->book_chapter_title,
            ]);
        }

        return $textbookChapter->fresh();
    }
}
