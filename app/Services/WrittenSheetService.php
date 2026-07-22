<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WrittenSubmission;
use App\Services\FillBlankImportService;
use App\Services\PracticeSetService;
use App\Services\WrittenSheetPdfService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class WrittenSheetService
{
    public function __construct(
        private PracticeSetService $practiceSetService,
        private WrittenSheetPdfService $pdfService,
        private FillBlankImportService $fillBlankImportService,
        private WrittenSheetPdfImportService $pdfImportService,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForAdmin(?int $gradeLevelId = null): Collection
    {
        return Worksheet::query()
            ->where('delivery_mode', WorksheetDeliveryMode::WRITTEN)
            ->with([
                'topic:id,name,syllabus_chapter_id',
                'topic.chapter:id,name,chapter_number,syllabus_version_id',
                'topic.chapter.syllabusVersion.gradeLevel:id,name',
                'chapter:id,name,chapter_number,syllabus_version_id',
                'chapter.syllabusVersion.gradeLevel:id,name',
                'verifier:id,name',
            ])
            ->withCount('questions')
            ->when($gradeLevelId, function ($query) use ($gradeLevelId) {
                $query->where(function ($inner) use ($gradeLevelId) {
                    $inner->whereHas('topic.chapter.syllabusVersion', fn ($q) => $q->where('grade_level_id', $gradeLevelId))
                        ->orWhereHas('chapter.syllabusVersion', fn ($q) => $q->where('grade_level_id', $gradeLevelId));
                });
            })
            ->orderByDesc('id')
            ->get()
            ->map(fn (Worksheet $worksheet) => $this->summary($worksheet));
    }

    /**
     * @param  list<int>  $questionIds
     */
    public function createFromTopic(
        SyllabusTopic $topic,
        array $questionIds,
        User $creator,
        ?string $notes = null,
    ): Worksheet {
        $topic->load('chapter.syllabusVersion.gradeLevel');

        $questions = Question::query()
            ->whereIn('id', $questionIds)
            ->where('syllabus_topic_id', $topic->id)
            ->get();

        if ($questions->count() !== count(array_unique($questionIds))) {
            throw new \InvalidArgumentException('Select valid questions from this topic only.');
        }

        $meta = $this->practiceSetService->prepareForCreate(
            $topic,
            PracticeSetTier::STARTER,
            $questions->count(),
        );

        $worksheet = Worksheet::create([
            'title' => $meta['title'].' — Written',
            'set_number' => $meta['set_number'],
            'set_code' => $meta['set_code'].'-W',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'notes' => $notes,
            'status' => Worksheet::STATUS_DRAFT,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => WrittenSheetStatus::DRAFT,
            'created_by' => $creator->id,
        ]);

        foreach (array_values($questionIds) as $index => $questionId) {
            $worksheet->questions()->attach($questionId, ['sort_order' => $index + 1]);
        }

        return $worksheet->loadCount('questions');
    }

    /**
     * @param  list<int>  $questionIds
     */
    public function createChapterTest(
        SyllabusChapter $chapter,
        array $questionIds,
        User $creator,
        ?string $notes = null,
    ): Worksheet {
        $questions = Question::query()
            ->whereIn('id', $questionIds)
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
            ->get();

        if ($questions->count() !== count(array_unique($questionIds))) {
            throw new \InvalidArgumentException('Select valid questions from this chapter only.');
        }

        $meta = $this->practiceSetService->prepareChapterTestCreate($chapter, $questions->count());

        $worksheet = Worksheet::create([
            'title' => $meta['title'].' — Written',
            'set_number' => $meta['set_number'],
            'set_code' => $meta['set_code'].'-W',
            'tier' => $meta['tier'],
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'notes' => $notes,
            'status' => Worksheet::STATUS_DRAFT,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => WrittenSheetStatus::DRAFT,
            'created_by' => $creator->id,
        ]);

        foreach (array_values($questionIds) as $index => $questionId) {
            $worksheet->questions()->attach($questionId, ['sort_order' => $index + 1]);
        }

        return $worksheet->loadCount('questions');
    }

    /**
     * Create fill-in-blank questions from manual / Cursor rows, then build the written sheet.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function createFromManualQuestions(
        SyllabusChapter $chapter,
        ?SyllabusTopic $topic,
        string $sheetKind,
        array $rows,
        User $creator,
        ?string $notes = null,
    ): Worksheet {
        $rows = array_values(array_filter($rows, fn (array $row) => trim((string) ($row['question_text'] ?? '')) !== ''));

        if ($rows === []) {
            throw new \InvalidArgumentException('Add at least one question.');
        }

        if ($sheetKind === 'test' || $topic === null) {
            $bankPurpose = $sheetKind === 'test'
                ? QuestionBankPurpose::CHAPTER_TEST
                : QuestionBankPurpose::PRACTICE_SET;

            $saved = $this->fillBlankImportService->saveChapterRows(
                $chapter,
                $this->normalizeManualRowsForChapter($chapter, $topic, $rows),
                $creator->id,
                Question::SOURCE_MANUAL,
                $bankPurpose,
            );
            $questionIds = collect($saved)->pluck('id')->all();

            if ($questionIds === []) {
                throw new \InvalidArgumentException('Add at least one valid question.');
            }

            return $this->createChapterTest($chapter, $questionIds, $creator, $notes);
        }

        if (! $topic) {
            throw new \InvalidArgumentException('Select a topic for a written practice sheet.');
        }

        $saved = $this->fillBlankImportService->saveRows(
            $topic,
            $this->normalizeManualRowsForTopic($topic, $rows),
            $creator->id,
            Question::SOURCE_MANUAL,
            QuestionBankPurpose::PRACTICE_SET,
        );
        $questionIds = collect($saved)->pluck('id')->all();

        return $this->createFromTopic($topic, $questionIds, $creator, $notes);
    }

    /**
     * Save answer-key rows for an externally uploaded worksheet PDF.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function createFromAnswerKey(
        SyllabusChapter $chapter,
        ?SyllabusTopic $topic,
        string $sheetKind,
        array $rows,
        User $creator,
        ?string $notes = null,
    ): Worksheet {
        $rows = array_values(array_filter($rows, fn (array $row) => trim((string) ($row['correct_answer'] ?? '')) !== ''));

        if ($rows === []) {
            throw new \InvalidArgumentException('Add at least one answer.');
        }

        $manualRows = [];

        foreach ($rows as $index => $row) {
            $questionText = trim((string) ($row['question_text'] ?? ''));

            if ($questionText === '') {
                $questionText = 'Q'.($index + 1).' — see worksheet PDF';
            }

            $manualRows[] = [
                ...$row,
                'question_text' => $questionText,
            ];
        }

        return $this->createFromManualQuestions(
            $chapter,
            $topic,
            $sheetKind,
            $manualRows,
            $creator,
            $notes,
        );
    }

    public function attachUploadedPdf(Worksheet $worksheet, string $sourcePath, ?string $token = null): Worksheet
    {
        if (! $worksheet->isWritten()) {
            throw new \InvalidArgumentException('This is not a written sheet.');
        }

        $path = $this->pdfImportService->attachToWorksheet($worksheet, $sourcePath);

        $worksheet->update([
            'written_pdf_path' => $path,
            'written_status' => WrittenSheetStatus::PENDING_REVIEW,
            'written_verified_at' => null,
            'written_verified_by' => null,
        ]);

        if ($token) {
            $this->pdfImportService->cleanupTokenDirectory($token);
        }

        return $worksheet->fresh();
    }

    public function usesUploadedPdf(Worksheet $worksheet): bool
    {
        $worksheet->loadMissing('questions');

        if ($worksheet->questions->isEmpty()) {
            return false;
        }

        return $worksheet->questions->every(
            fn (Question $question) => (bool) preg_match('/^Q\d+\s*[—-]\s*see worksheet PDF$/u', trim((string) $question->question_text)),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeManualRowsForTopic(SyllabusTopic $topic, array $rows): array
    {
        return array_map(function (array $row) use ($topic) {
            return [
                'question_text' => trim((string) ($row['question_text'] ?? '')),
                'answer_format' => $row['answer_format'] ?? 'text',
                'correct_answer' => trim((string) ($row['correct_answer'] ?? '')),
                'decimal_places' => $row['decimal_places'] ?? null,
                'explanation' => $row['explanation'] ?? null,
                'method_hint' => $row['method_hint'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
            ];
        }, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeManualRowsForChapter(SyllabusChapter $chapter, ?SyllabusTopic $defaultTopic, array $rows): array
    {
        $chapter->loadMissing('topics');

        return array_map(function (array $row) use ($chapter, $defaultTopic) {
            $topicId = $row['syllabus_topic_id'] ?? $row['topic_id'] ?? null;
            $topicName = trim((string) ($row['topic_name'] ?? $row['topic'] ?? ''));

            if (! $topicId && $topicName !== '') {
                $topicId = $chapter->topics->first(
                    fn (SyllabusTopic $chapterTopic) => mb_strtolower($chapterTopic->name) === mb_strtolower($topicName),
                )?->id;
            }

            if (! $topicId && $defaultTopic) {
                $topicId = $defaultTopic->id;
                $topicName = $defaultTopic->name;
            }

            if (! $topicId && $chapter->topics->isNotEmpty()) {
                $topicId = $chapter->topics->first()->id;
                $topicName = $chapter->topics->first()->name;
            }

            return [
                'question_text' => trim((string) ($row['question_text'] ?? '')),
                'topic_name' => $topicName,
                'syllabus_topic_id' => $topicId,
                'answer_format' => $row['answer_format'] ?? 'text',
                'correct_answer' => trim((string) ($row['correct_answer'] ?? '')),
                'decimal_places' => $row['decimal_places'] ?? null,
                'explanation' => $row['explanation'] ?? null,
                'method_hint' => $row['method_hint'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
            ];
        }, $rows);
    }

    public function generatePdf(Worksheet $worksheet): Worksheet
    {
        if (! $worksheet->isWritten()) {
            throw new \InvalidArgumentException('This is not a written sheet.');
        }

        if ($worksheet->questions()->count() === 0) {
            throw new \InvalidArgumentException('Add at least one question before generating the PDF.');
        }

        $path = $this->pdfService->generate($worksheet);

        $worksheet->update([
            'written_pdf_path' => $path,
            'written_status' => WrittenSheetStatus::PENDING_REVIEW,
            'written_verified_at' => null,
            'written_verified_by' => null,
        ]);

        return $worksheet->fresh();
    }

    public function verify(Worksheet $worksheet, User $admin): Worksheet
    {
        if (! $worksheet->isWritten()) {
            throw new \InvalidArgumentException('This is not a written sheet.');
        }

        if ($worksheet->written_status !== WrittenSheetStatus::PENDING_REVIEW || ! $worksheet->written_pdf_path) {
            throw new \InvalidArgumentException('Generate and review the PDF before verifying.');
        }

        $worksheet->update([
            'written_status' => WrittenSheetStatus::VERIFIED,
            'status' => Worksheet::STATUS_PUBLISHED,
            'written_verified_at' => now(),
            'written_verified_by' => $admin->id,
        ]);

        return $worksheet->fresh();
    }

    public function reject(Worksheet $worksheet): Worksheet
    {
        if (! $worksheet->isWritten()) {
            throw new \InvalidArgumentException('This is not a written sheet.');
        }

        $worksheet->update([
            'written_status' => WrittenSheetStatus::DRAFT,
            'status' => Worksheet::STATUS_DRAFT,
            'written_verified_at' => null,
            'written_verified_by' => null,
        ]);

        return $worksheet->fresh();
    }

    public function hasStudentSubmissions(Worksheet $worksheet): bool
    {
        return WrittenSubmission::query()
            ->whereHas('assignment', fn ($query) => $query
                ->where('worksheet_id', $worksheet->id)
                ->whereNot('status', SetAssignment::STATUS_CANCELLED))
            ->exists();
    }

    public function canReplacePdf(Worksheet $worksheet): bool
    {
        return $worksheet->isWritten()
            && $worksheet->written_pdf_path
            && ! $this->hasStudentSubmissions($worksheet);
    }

    public function canManagePdf(Worksheet $worksheet): bool
    {
        return $worksheet->isWritten() && ! $this->hasStudentSubmissions($worksheet);
    }

    public function replacePdf(Worksheet $worksheet, string $sourcePath, ?string $token = null): Worksheet
    {
        if (! $worksheet->isWritten()) {
            throw new \InvalidArgumentException('This is not a written sheet.');
        }

        if ($this->hasStudentSubmissions($worksheet)) {
            throw new \InvalidArgumentException(
                'Cannot replace the worksheet PDF after a student has uploaded their written work.',
            );
        }

        $oldPath = $worksheet->written_pdf_path;
        $newPath = $this->pdfImportService->attachToWorksheet($worksheet, $sourcePath);

        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('public')->delete($oldPath);
        }

        if ($token) {
            $this->pdfImportService->cleanupTokenDirectory($token);
        }

        $updates = [
            'written_pdf_path' => $newPath,
        ];

        if (! $worksheet->isWrittenVerified()) {
            $updates['written_status'] = WrittenSheetStatus::PENDING_REVIEW;
        }

        $worksheet->update($updates);

        return $worksheet->fresh();
    }

    public function removePdf(Worksheet $worksheet): Worksheet
    {
        if (! $worksheet->isWritten()) {
            throw new \InvalidArgumentException('This is not a written sheet.');
        }

        if ($this->hasStudentSubmissions($worksheet)) {
            throw new \InvalidArgumentException(
                'Cannot remove the worksheet PDF after a student has uploaded their written work.',
            );
        }

        if (! $worksheet->written_pdf_path) {
            throw new \InvalidArgumentException('This sheet has no PDF to remove.');
        }

        Storage::disk('public')->delete($worksheet->written_pdf_path);

        $worksheet->update([
            'written_pdf_path' => null,
            'written_status' => WrittenSheetStatus::PENDING_REVIEW,
            'written_verified_at' => null,
            'written_verified_by' => null,
            'status' => Worksheet::STATUS_DRAFT,
        ]);

        return $worksheet->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Worksheet $worksheet): array
    {
        $chapter = $worksheet->isChapterScope()
            ? $worksheet->chapter
            : $worksheet->topic?->chapter;

        return [
            'id' => $worksheet->id,
            'set_code' => $worksheet->set_code,
            'title' => $worksheet->title,
            'kind_label' => $worksheet->isChapterTest() ? 'Test' : 'Practice',
            'scope' => $worksheet->scope,
            'questions_count' => $worksheet->questions_count,
            'written_status' => $worksheet->written_status,
            'written_status_label' => WrittenSheetStatus::label($worksheet->written_status ?? WrittenSheetStatus::DRAFT),
            'status' => $worksheet->status,
            'written_pdf_url' => $worksheet->writtenPdfUrl(),
            'uses_uploaded_pdf' => $this->usesUploadedPdf($worksheet),
            'chapter_name' => $chapter?->name,
            'topic_name' => $worksheet->topic?->name,
            'class_name' => $chapter?->syllabusVersion?->gradeLevel?->name
                ?? $worksheet->topic?->chapter?->syllabusVersion?->gradeLevel?->name,
            'verified_at' => $worksheet->written_verified_at?->toDateTimeString(),
            'verified_by' => $worksheet->verifier?->name,
            'can_assign' => $worksheet->isWrittenVerified(),
            'can_replace_pdf' => $this->canReplacePdf($worksheet),
            'can_manage_pdf' => $this->canManagePdf($worksheet),
            'has_student_submissions' => $this->hasStudentSubmissions($worksheet),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Worksheet $worksheet): array
    {
        $worksheet->load([
            'questions.options',
            'questions.blankAnswer',
            'topic.chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.gradeLevel',
            'verifier:id,name',
        ])->loadCount('questions');

        return [
            ...$this->summary($worksheet),
            'notes' => $worksheet->notes,
            'questions' => $worksheet->questions->values()->map(function (Question $question, int $index) {
                return [
                    'id' => $question->id,
                    'number' => $index + 1,
                    'question_text' => $question->question_text,
                    'type' => $question->type,
                    'correct_answer' => $question->isMcq()
                        ? $question->options->firstWhere('is_correct', true)?->option_text
                        : $question->blankAnswer?->correct_answer,
                    'source' => $question->source,
                ];
            })->all(),
        ];
    }
}
