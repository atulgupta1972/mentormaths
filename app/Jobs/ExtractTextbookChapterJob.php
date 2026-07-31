<?php

namespace App\Jobs;

use App\Models\TextbookChapter;
use App\Services\TextbookChapterExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExtractTextbookChapterJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $textbookChapterId) {}

    public function handle(TextbookChapterExtractionService $extractionService): void
    {
        $chapter = TextbookChapter::query()->find($this->textbookChapterId);

        if (! $chapter || ! in_array($chapter->status, [
            TextbookChapter::STATUS_DRAFT,
            TextbookChapter::STATUS_FAILED,
            TextbookChapter::STATUS_EXTRACTING,
        ], true)) {
            return;
        }

        $chapter->update([
            'status' => TextbookChapter::STATUS_EXTRACTING,
            'extraction_error' => null,
        ]);

        try {
            $items = $extractionService->extract($chapter);

            $chapter->update([
                'status' => TextbookChapter::STATUS_REVIEW,
                'extraction_items' => $items,
                'extracted_at' => now(),
                'extraction_error' => null,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Textbook chapter extraction failed', [
                'textbook_chapter_id' => $chapter->id,
                'message' => $exception->getMessage(),
            ]);

            $chapter->update([
                'status' => TextbookChapter::STATUS_FAILED,
                'extraction_error' => $exception->getMessage(),
            ]);
        }
    }
}
