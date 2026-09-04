<?php

namespace App\Console\Commands;

use App\Models\TextbookChapter;
use App\Services\TextbookChapterPublishService;
use Illuminate\Console\Command;

class BackfillFillBlankDiagrams extends Command
{
    protected $signature = 'content:backfill-fill-blank-diagrams
                            {--chapter= : Limit to one textbook_chapter_id}';

    protected $description = 'Copy missing figures from published MCQs onto fill-in-blank (and written) questions';

    public function handle(TextbookChapterPublishService $publishService): int
    {
        $chapterId = $this->option('chapter');
        $chapter = null;

        if ($chapterId) {
            $chapter = TextbookChapter::query()->findOrFail((int) $chapterId);
        }

        $result = $publishService->backfillMissingFillBlankDiagrams($chapter);

        $this->info("Scanned {$result['scanned']} fill-in-blank question(s); fixed {$result['fixed']} missing figure(s).");

        return self::SUCCESS;
    }
}
