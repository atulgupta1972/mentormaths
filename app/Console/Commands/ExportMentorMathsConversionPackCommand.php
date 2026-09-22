<?php

namespace App\Console\Commands;

use App\Services\MentorMathsConversionPackService;
use Illuminate\Console\Command;

class ExportMentorMathsConversionPackCommand extends Command
{
    protected $signature = 'mentormaths:export-conversion-pack
                            {--ids= : Comma-separated textbook_chapter ids}
                            {--done : Export all done MentorMaths chapters}
                            {--book= : Limit --done to a book code (e.g. mm2)}
                            {--path= : Relative path under storage/app}';

    protected $description = 'Export MentorMaths fill-blank conversion packs for moving local work to another server';

    public function handle(MentorMathsConversionPackService $packs): int
    {
        $ids = [];

        if ($this->option('done')) {
            $ids = $packs->doneChapters($this->option('book') ?: null)->pluck('id')->all();
        }

        if ($this->option('ids')) {
            $ids = array_merge($ids, array_filter(array_map('intval', explode(',', (string) $this->option('ids')))));
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            $this->error('Pass --ids=108,110 or --done (optionally --book=mm2).');

            return self::FAILURE;
        }

        $written = $packs->writePackFile($ids, $this->option('path') ?: null);
        $pack = $written['pack'];

        $this->info('Exported '.$pack['chapter_count'].' chapter(s).');
        foreach ($pack['chapters'] as $chapter) {
            $match = $chapter['match'];
            $this->line(sprintf(
                '  - %s · Ch %s — %s (%d fill-blank ready)',
                $match['book_code'] ?? '?',
                $match['chapter_number'] ?? '?',
                $match['title'] ?? '?',
                (int) ($chapter['fill_blank_ready_count'] ?? 0),
            ));
        }
        $this->newLine();
        $this->info('File: '.$written['absolute_path']);
        $this->comment('Copy this JSON to prod, then run: php artisan mentormaths:import-conversion-pack "'.$written['path'].'"');

        return self::SUCCESS;
    }
}
