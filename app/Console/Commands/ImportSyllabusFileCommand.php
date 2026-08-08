<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusVersion;
use App\Services\SyllabusImportService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

class ImportSyllabusFileCommand extends Command
{
    protected $signature = 'syllabus:import-file
        {file : Path to .xlsx syllabus file}
        {--board=CBSE : Board code}
        {--class= : Class label e.g. "Class 4"}
        {--year= : Academic year name e.g. "2026-27" (defaults to active year)}
        {--subject=MATHS : Subject code}
        {--dry-run : Parse only; do not write to database}';

    protected $description = 'Replace a syllabus version with rows from an Excel file (same as admin Import & replace)';

    public function handle(SyllabusImportService $importService): int
    {
        $path = $this->argument('file');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $classLabel = trim((string) $this->option('class'));
        if ($classLabel === '') {
            $this->error('Pass --class="Class 4" (or the exact grade level name).');

            return self::FAILURE;
        }

        $board = Board::query()->where('code', strtoupper((string) $this->option('board')))->first();
        $grade = GradeLevel::query()->where('name', $classLabel)->first();
        $subject = Subject::query()->where('code', strtoupper((string) $this->option('subject')))->first();
        $yearName = trim((string) $this->option('year'));
        $year = $yearName !== ''
            ? AcademicYear::query()->where('name', $yearName)->first()
            : AcademicYear::active();

        if (! $board || ! $grade || ! $subject || ! $year) {
            $this->error('Could not resolve board, class, subject, or academic year. Check --board, --class, --year, --subject.');

            return self::FAILURE;
        }

        $file = new UploadedFile($path, basename($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        try {
            $headerInfo = $importService->describeFileHeaders($file);
            $rows = $importService->parseFileToPreviewRows($file);
        } catch (\Throwable $e) {
            $this->error('Could not read Excel: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($headerInfo['missing'] !== []) {
            $this->error('Missing columns: '.implode(', ', $headerInfo['missing']));

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->error('No topic rows found in the file.');

            return self::FAILURE;
        }

        $this->info("Parsed {$rows->count()} topic row(s).");
        $this->line('Chapters: '.$rows->pluck('chapter_name')->unique()->implode(', '));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — database unchanged.');

            return self::SUCCESS;
        }

        $version = SyllabusVersion::firstOrCreate(
            [
                'board_id' => $board->id,
                'grade_level_id' => $grade->id,
                'subject_id' => $subject->id,
                'academic_year_id' => $year->id,
            ],
            ['status' => SyllabusVersion::STATUS_DRAFT],
        );

        $count = $importService->import($file, $version);

        $this->info("Imported {$count} topic(s) into syllabus #{$version->id} ({$board->code} {$grade->name} · {$year->name}).");

        return self::SUCCESS;
    }
}
