<?php

namespace App\Console\Commands;

use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Support\QuestionBankPurpose;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ExportFormulaBankCommand extends Command
{
    protected $signature = 'formula-bank:export
                            {--chapter= : Export only this syllabus_chapters.id}
                            {--path=storage/app/formula-bank-export : Output directory}';

    protected $description = 'Export formula / concept cards as chapter JSON for importing on another environment (e.g. prod)';

    public function handle(): int
    {
        $outputDir = base_path(trim((string) $this->option('path'), '/\\'));
        File::ensureDirectoryExists($outputDir);

        $chapterId = $this->option('chapter');

        $query = Question::query()
            ->with([
                'topic:id,name,syllabus_chapter_id,sort_order',
                'topic.chapter:id,name,chapter_number,syllabus_version_id',
                'topic.chapter.syllabusVersion.board:id,code',
                'topic.chapter.syllabusVersion.gradeLevel:id,name',
                'options' => fn ($q) => $q->orderBy('sort_order'),
            ])
            ->where('bank_purpose', QuestionBankPurpose::FORMULA)
            ->whereHas('topic.chapter');

        if ($chapterId) {
            $query->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', (int) $chapterId));
        }

        $questions = $query->orderBy('id')->get();

        if ($questions->isEmpty()) {
            $this->warn('No formula cards found to export.');

            return self::SUCCESS;
        }

        $byChapter = $questions->groupBy(fn (Question $q) => (int) $q->topic->syllabus_chapter_id);
        $manifest = [];
        $exported = 0;

        foreach ($byChapter as $cid => $chapterQuestions) {
            /** @var SyllabusChapter $chapter */
            $chapter = $chapterQuestions->first()->topic->chapter;
            $board = $chapter->syllabusVersion?->board?->code ?? 'board';
            $grade = $chapter->syllabusVersion?->gradeLevel?->name ?? 'class';
            $slug = Str::slug($board.'-'.$grade.'-ch'.$chapter->chapter_number.'-'.$chapter->name);
            $filename = "{$slug}.json";
            $path = $outputDir.DIRECTORY_SEPARATOR.$filename;

            $payload = [
                'meta' => [
                    'board' => $board,
                    'grade' => $grade,
                    'chapter_number' => $chapter->chapter_number,
                    'chapter_name' => $chapter->name,
                    'local_chapter_id' => $chapter->id,
                    'card_count' => $chapterQuestions->count(),
                    'exported_at' => now()->toIso8601String(),
                ],
                'questions' => $chapterQuestions->values()->map(function (Question $question) {
                    $options = $question->options->values();
                    $correctIndex = $options->search(fn ($opt) => (bool) $opt->is_correct);
                    if ($correctIndex === false) {
                        $correctIndex = 0;
                    }

                    return [
                        'topic' => $question->topic->name,
                        'question' => $question->question_text,
                        'options' => $options->pluck('option_text')->values()->all(),
                        'correct_index' => (int) $correctIndex,
                        'explanation' => $question->explanation,
                        'difficulty' => $question->difficulty,
                    ];
                })->all(),
            ];

            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

            $manifest[] = [
                'file' => $filename,
                'board' => $board,
                'grade' => $grade,
                'chapter' => "Ch {$chapter->chapter_number} — {$chapter->name}",
                'cards' => $chapterQuestions->count(),
            ];
            $exported += $chapterQuestions->count();
            $this->line("Wrote {$filename} ({$chapterQuestions->count()} cards)");
        }

        File::put(
            $outputDir.DIRECTORY_SEPARATOR.'README.txt',
            $this->readmeText($manifest, $exported),
        );

        $this->newLine();
        $this->info("Exported {$exported} formula cards into {$byChapter->count()} chapter file(s).");
        $this->info("Folder: {$outputDir}");
        $this->comment('On prod: Formula bank → open matching chapter → paste that chapter JSON → Preview → Save.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{file: string, board: string, grade: string, chapter: string, cards: int}>  $manifest
     */
    private function readmeText(array $manifest, int $exported): string
    {
        $lines = [
            'Formula bank export',
            '===================',
            '',
            "Total cards: {$exported}",
            'Exported at: '.now()->toDateTimeString(),
            '',
            'How to import on production',
            '---------------------------',
            '1. git pull / deploy code if needed (Formula bank UI must exist).',
            '2. Open Admin → Teaching → Formula bank.',
            '3. Select the same Board + Class as in each file meta.',
            '4. Open the matching chapter.',
            '5. Paste the JSON from that chapter file into the import box.',
            '6. Preview → verify → Save (creates cards + formula sets).',
            '',
            'Important',
            '---------',
            '- Topic names in JSON must match prod syllabus topic names (same wording).',
            '- Do not SQL-dump question IDs — local and prod IDs differ.',
            '- Re-importing the same JSON will create duplicate cards; import each chapter once.',
            '',
            'Files',
            '-----',
        ];

        foreach ($manifest as $row) {
            $lines[] = "- {$row['file']}: {$row['board']} {$row['grade']} · {$row['chapter']} · {$row['cards']} cards";
        }

        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }
}
