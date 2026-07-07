<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Support\QuestionBankPurpose;
use App\Services\AdminGradeContext;
use App\Services\FillBlankImportService;
use App\Services\McqImportService;
use App\Services\PdfPageImageService;
use App\Services\PdfTextExtractionService;
use App\Services\PdfWorksheetImportService;
use App\Services\QuestionDiagramService;
use App\Services\QuestionMethodHintService;
use App\Services\QuestionSaveConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class QuestionController extends Controller
{
    public function __construct(
        private McqImportService $importService,
        private FillBlankImportService $fillBlankImportService,
        private PdfTextExtractionService $pdfService,
        private AdminGradeContext $gradeContext,
        private QuestionMethodHintService $methodHintService,
        private QuestionSaveConfirmation $saveConfirmation,
    ) {}

    public function topicIndex(Request $request, SyllabusTopic $topic): Response
    {
        $topic->load(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel']);

        $gradeLevel = $topic->chapter?->syllabusVersion?->gradeLevel;
        $board = $topic->chapter?->syllabusVersion?->board;
        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }
        if ($board) {
            $this->gradeContext->persistBoard($request, $board->id);
        }

        $query = Question::query()
            ->with('options')
            ->where('syllabus_topic_id', $topic->id)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where('question_text', 'like', "%{$search}%");
            });

        $questions = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('Admin/Questions/TopicQuestions', [
            'topic' => [
                'id' => $topic->id,
                'name' => $topic->name,
                'difficulty' => $topic->difficulty,
                'chapter_id' => $topic->syllabus_chapter_id,
                'chapter_number' => $topic->chapter->chapter_number,
                'chapter_name' => $topic->chapter->name,
                'grade_level_id' => $gradeLevel?->id,
                'grade_name' => $gradeLevel?->name,
                'board_id' => $board?->id,
                'board_code' => $board?->code,
            ],
            'board' => $board?->only(['id', 'code', 'name']),
            'questions' => $questions,
            'filters' => [
                'search' => $request->string('search')->toString(),
            ],
            'hintStats' => $this->methodHintService->statsForTopic($topic),
            'canClearBank' => ! $topic->practiceSets()->exists(),
        ]);
    }

    public function clearTopicBank(SyllabusTopic $topic): RedirectResponse
    {
        if ($topic->practiceSets()->exists()) {
            return redirect()
                ->back()
                ->with('error', 'This topic has a packaged practice set. Delete the set first, or remove questions one by one.');
        }

        $count = $topic->questions()->count();

        if ($count === 0) {
            return redirect()
                ->back()
                ->with('warning', 'No questions to delete in this topic.');
        }

        DB::transaction(function () use ($topic) {
            $topic->questions()->each(fn (Question $question) => $question->delete());
        });

        return redirect()
            ->route('admin.questions.chapters.show', $topic->syllabus_chapter_id)
            ->with('success', "Deleted {$count} question".($count === 1 ? '' : 's')." from {$topic->name}.");
    }

    public function clearChapterPracticeBank(SyllabusChapter $chapter): RedirectResponse
    {
        $questions = Question::query()
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
            ->where('bank_purpose', QuestionBankPurpose::PRACTICE_SET)
            ->whereDoesntHave('worksheets')
            ->get();

        if ($questions->isEmpty()) {
            return redirect()
                ->back()
                ->with('warning', 'No practice-set questions to delete in this chapter.');
        }

        $count = $questions->count();

        DB::transaction(function () use ($questions) {
            $questions->each(fn (Question $question) => $question->delete());
        });

        return redirect()
            ->route('admin.questions.chapters.show', $chapter->id)
            ->with('success', "Deleted {$count} practice-set question".($count === 1 ? '' : 's').' from this chapter.');
    }

    public function generateMethodHints(Request $request, SyllabusTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'overwrite' => ['sometimes', 'boolean'],
            'sanitize_explanations' => ['sometimes', 'boolean'],
        ]);

        $result = $this->methodHintService->fillForTopic(
            $topic,
            (bool) ($validated['overwrite'] ?? false),
            (bool) ($validated['sanitize_explanations'] ?? true),
        );

        if ($result['total'] === 0) {
            return redirect()
                ->route('admin.questions.topics.show', $topic)
                ->with('warning', 'No questions in this topic yet.');
        }

        $parts = [];
        if ($result['updated'] > 0) {
            $parts[] = "Generated method hints for {$result['updated']} question".($result['updated'] === 1 ? '' : 's');
        }
        if ($result['skipped'] > 0) {
            $parts[] = "{$result['skipped']} already had hints";
        }
        if ($result['unresolved'] > 0) {
            $parts[] = "{$result['unresolved']} need manual hints (edit individually)";
        }
        if ($result['explanations_cleaned'] > 0) {
            $parts[] = "Removed answer keys from {$result['explanations_cleaned']} teacher explanation".($result['explanations_cleaned'] === 1 ? '' : 's');
        }

        $message = $parts !== [] ? implode('. ', $parts).'.' : 'No changes were needed — all questions already have method hints.';

        return redirect()
            ->back(fallback: route('admin.questions.topics.show', $topic))
            ->with($result['updated'] > 0 || $result['explanations_cleaned'] > 0 ? 'success' : 'warning', $message);
    }

    public function create(Request $request): Response
    {
        return $this->renderCreate($request);
    }

    public function createFillInBlank(Request $request): Response
    {
        return $this->renderFillInBlankCreate($request);
    }

    public function previewFillBlankImport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'json' => ['required', 'string'],
        ]);

        try {
            $rows = $this->fillBlankImportService->parseJson($validated['json']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.questions.create-fill-in-blank', array_filter([
                'syllabus_topic_id' => $validated['syllabus_topic_id'],
                'syllabus_chapter_id' => SyllabusTopic::query()
                    ->whereKey($validated['syllabus_topic_id'])
                    ->value('syllabus_chapter_id'),
            ]))
            ->with('import_rows', $rows);
    }

    public function storeBulkFillBlank(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.question_text' => ['required', 'string'],
            'rows.*.answer_format' => ['required', 'in:'.implode(',', \App\Models\QuestionBlankAnswer::formats())],
            'rows.*.correct_answer' => ['required', 'string', 'max:64'],
            'rows.*.decimal_places' => ['nullable', 'integer', 'min:0', 'max:6'],
            'rows.*.explanation' => ['nullable', 'string'],
            'rows.*.method_hint' => ['nullable', 'string'],
            'rows.*.difficulty' => ['nullable', 'string', 'max:20'],
        ]);

        $topic = SyllabusTopic::findOrFail($validated['syllabus_topic_id']);

        $saved = $this->fillBlankImportService->saveRows(
            $topic,
            $validated['rows'],
            $request->user()->id,
            Question::SOURCE_AI,
            QuestionBankPurpose::PRACTICE_SET,
        );

        $topic->load('chapter');

        $confirmation = $this->saveConfirmation->build(
            $saved,
            QuestionBankPurpose::PRACTICE_SET,
            topic: $topic,
        );

        return redirect()
            ->route('admin.questions.chapters.show', $topic->syllabus_chapter_id)
            ->with('success', count($saved).' fill-in-the-blank question(s) saved successfully.')
            ->with('save_confirmation', $confirmation);
    }

    public function chapterFillBlankPrompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'plan' => ['required', 'array', 'min:1'],
            'plan.*.topic_id' => ['required', 'exists:syllabus_topics,id'],
            'plan.*.topic_name' => ['nullable', 'string'],
            'plan.*.easy' => ['nullable', 'integer', 'min:0'],
            'plan.*.medium' => ['nullable', 'integer', 'min:0'],
            'plan.*.hard' => ['nullable', 'integer', 'min:0'],
        ]);

        $chapter = SyllabusChapter::query()->findOrFail($validated['syllabus_chapter_id']);

        try {
            $prompt = $this->fillBlankImportService->cursorPromptForChapter($chapter, $validated['plan']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.questions.create-fill-in-blank', [
                'syllabus_chapter_id' => $chapter->id,
                'scope' => 'chapter',
            ])
            ->with('chapter_fill_blank_cursor_prompt', $prompt)
            ->with('chapter_plan', $validated['plan']);
    }

    public function storeBulkChapterFillBlank(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.syllabus_topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'rows.*.topic_name' => ['nullable', 'string'],
            'rows.*.question_text' => ['required', 'string'],
            'rows.*.answer_format' => ['required', 'in:'.implode(',', \App\Models\QuestionBlankAnswer::formats())],
            'rows.*.correct_answer' => ['required', 'string', 'max:64'],
            'rows.*.decimal_places' => ['nullable', 'integer', 'min:0', 'max:6'],
            'rows.*.explanation' => ['nullable', 'string'],
            'rows.*.method_hint' => ['nullable', 'string'],
            'rows.*.difficulty' => ['nullable', 'string', 'max:20'],
        ]);

        $chapter = SyllabusChapter::query()
            ->with('topics')
            ->findOrFail($validated['syllabus_chapter_id']);

        try {
            $saved = DB::transaction(function () use ($validated, $request, $chapter) {
                return $this->fillBlankImportService->saveChapterRows(
                    $chapter,
                    $validated['rows'],
                    $request->user()->id,
                    Question::SOURCE_AI,
                    QuestionBankPurpose::PRACTICE_SET,
                );
            });
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $confirmation = $this->saveConfirmation->build(
            $saved,
            QuestionBankPurpose::PRACTICE_SET,
            $chapter,
        );

        return redirect()
            ->route('admin.questions.chapters.show', $chapter->id)
            ->with('success', count($saved).' fill-in-the-blank question(s) saved successfully.')
            ->with('save_confirmation', $confirmation);
    }

    public function extractPdf(Request $request): Response
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'pdf' => ['required', 'file', 'max:10240'],
            'pdf_mode' => ['required', 'in:sums,mcq'],
        ], [
            'pdf.required' => 'Choose a PDF file first.',
            'pdf.max' => 'PDF must be smaller than 10 MB.',
            'syllabus_topic_id.required' => 'Select a syllabus topic before uploading.',
        ]);

        if (! $this->isPdfUpload($request->file('pdf'))) {
            return $this->renderCreate($request, [
                'error' => 'Please upload a valid PDF file (.pdf).',
            ]);
        }

        $topic = SyllabusTopic::with(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear'])
            ->findOrFail($validated['syllabus_topic_id']);

        try {
            $text = $this->pdfService->extract($request->file('pdf'));
        } catch (\InvalidArgumentException $e) {
            return $this->renderCreate($request, [
                'error' => $e->getMessage(),
                'importMode' => $validated['pdf_mode'] === 'mcq' ? 'pdf_mcq' : 'pdf_sums',
                'selectedTopicId' => $topic->id,
            ]);
        }

        $prompt = $validated['pdf_mode'] === 'mcq'
            ? $this->importService->cursorPromptFromMcqPdf($topic, $text)
            : $this->importService->cursorPromptFromSumsPdf($topic, $text);

        $overrides = [
            'cursorPrompt' => $prompt,
            'extractedPreview' => mb_substr($text, 0, 3000),
            'pdfFileName' => $request->file('pdf')->getClientOriginalName(),
            'importMode' => $validated['pdf_mode'] === 'mcq' ? 'pdf_mcq' : 'pdf_sums',
            'selectedTopicId' => $topic->id,
            'pdfExtracted' => true,
        ];

        if ($validated['pdf_mode'] === 'mcq') {
            try {
                $directRows = $this->importService->parseFromWorksheetText($text);
                if ($directRows !== []) {
                    $overrides['importRows'] = $directRows;
                    $overrides['pdfDirectParsed'] = true;
                }
            } catch (\InvalidArgumentException) {
                // Fall back to Cursor prompt flow.
            }
        }

        return $this->renderCreate($request, $overrides);
    }

    public function extractPdfWorksheet(Request $request): Response
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'pdf' => ['required', 'file', 'max:20480'],
        ], [
            'pdf.required' => 'Choose a PDF file first.',
            'pdf.max' => 'PDF must be smaller than 20 MB.',
            'syllabus_topic_id.required' => 'Select a syllabus topic before uploading.',
        ]);

        if (! $this->isPdfUpload($request->file('pdf'))) {
            return $this->renderCreate($request, [
                'error' => 'Please upload a valid PDF file (.pdf).',
                'importMode' => 'pdf_worksheet',
                'selectedTopicId' => $validated['syllabus_topic_id'],
            ]);
        }

        $topic = SyllabusTopic::with(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear'])
            ->findOrFail($validated['syllabus_topic_id']);

        try {
            $result = app(PdfWorksheetImportService::class)->process($request->file('pdf'));
        } catch (\InvalidArgumentException $e) {
            return $this->renderCreate($request, [
                'error' => $e->getMessage(),
                'importMode' => 'pdf_worksheet',
                'selectedTopicId' => $topic->id,
            ]);
        }

        $rowsWithPreviews = collect($result['rows'])->map(function (array $row, int $index) use ($result) {
            $pageIndex = $result['page_assignments'][$index] ?? null;
            $preview = $pageIndex !== null && isset($result['page_urls'][$pageIndex])
                ? $result['page_urls'][$pageIndex]
                : null;

            return array_merge($row, [
                'diagram_preview_url' => $preview,
            ]);
        })->all();

        return $this->renderCreate($request, [
            'importMode' => 'pdf_worksheet',
            'selectedTopicId' => $topic->id,
            'importRows' => $rowsWithPreviews,
            'pdfFileName' => $request->file('pdf')->getClientOriginalName(),
            'pdfExtracted' => true,
            'pdfDirectParsed' => $result['parsed_from_text'],
            'pdfImportToken' => $result['token'],
            'referencePdfUrl' => $result['pdf_url'],
            'pdfPageCount' => $result['page_count'],
            'pdfImportWarning' => $result['warning'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function renderFillInBlankCreate(Request $request, array $overrides = []): Response
    {
        $grade = $this->gradeContext->resolve($request);
        $topicId = $overrides['selectedTopicId']
            ?? ($request->integer('syllabus_topic_id') ?: null);
        $chapterId = $request->integer('syllabus_chapter_id') ?: null;

        if ($topicId && ! $chapterId) {
            $chapterId = SyllabusTopic::query()->whereKey($topicId)->value('syllabus_chapter_id');
        }

        $topic = $topicId
            ? SyllabusTopic::with(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear'])->find($topicId)
            : null;

        $promptOptions = [
            'total' => max(1, min(50, $request->integer('total') ?: 6)),
            'easy' => max(0, $request->integer('easy') ?: 2),
            'medium' => max(0, $request->integer('medium') ?: 2),
            'hard' => max(0, $request->integer('hard') ?: 2),
            'focus' => $request->string('focus')->toString(),
        ];

        $activePrompt = $overrides['cursorPrompt'] ?? null;
        if ($activePrompt === null && $topic) {
            $activePrompt = $this->fillBlankImportService->cursorPrompt($topic, $promptOptions);
        }

        $chapters = $this->chapterOptions($grade?->id);

        $scope = $request->string('scope')->toString() === 'chapter' ? 'chapter' : 'topic';

        return Inertia::render('Admin/Questions/FillInBlankCreate', [
            'chapters' => $chapters,
            'chapterTopics' => $chapterId ? $this->topicsForChapter($chapterId) : collect(),
            'topicsByChapter' => $chapters->mapWithKeys(fn ($chapter) => [
                $chapter['id'] => $this->topicsForChapter($chapter['id'])->values()->all(),
            ])->all(),
            'selectedChapterId' => $chapterId,
            'selectedTopicId' => $topicId,
            'scope' => $scope,
            'chapterPlan' => session('chapter_plan', []),
            'topic' => $topic,
            'selectedGrade' => $grade?->only(['id', 'name']),
            'cursorPrompt' => session('chapter_fill_blank_cursor_prompt') ?? ($scope === 'topic' ? $activePrompt : null),
            'promptOptions' => $promptOptions,
            'initialImportRows' => $overrides['importRows'] ?? null,
            'pageError' => $overrides['error'] ?? null,
        ]);
    }

    private function renderCreate(Request $request, array $overrides = []): Response
    {
        $grade = $this->gradeContext->resolve($request);
        $topicId = $overrides['selectedTopicId']
            ?? ($request->integer('syllabus_topic_id') ?: null);
        $chapterId = $request->integer('syllabus_chapter_id') ?: null;

        if ($topicId && ! $chapterId) {
            $chapterId = SyllabusTopic::query()->whereKey($topicId)->value('syllabus_chapter_id');
        }

        $topic = $topicId
            ? SyllabusTopic::with(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear'])->find($topicId)
            : null;

        $promptOptions = [
            'total' => max(1, min(50, $request->integer('total') ?: 6)),
            'easy' => max(0, $request->integer('easy') ?: 2),
            'medium' => max(0, $request->integer('medium') ?: 2),
            'hard' => max(0, $request->integer('hard') ?: 2),
            'focus' => $request->string('focus')->toString(),
        ];

        $importMode = $overrides['importMode'] ?? $request->string('mode')->toString();
        if (! in_array($importMode, ['custom', 'pdf_sums', 'pdf_mcq', 'pdf_worksheet'], true)) {
            $importMode = 'custom';
        }

        $activePrompt = $overrides['cursorPrompt'] ?? null;
        if ($activePrompt === null && $topic && $importMode !== 'pdf_worksheet') {
            $activePrompt = $this->importService->cursorPrompt($topic, $promptOptions);
        }

        $chapters = $this->chapterOptions($grade?->id);

        $scope = $request->string('scope')->toString() === 'chapter' ? 'chapter' : 'topic';

        return Inertia::render('Admin/Questions/Create', [
            'chapters' => $chapters,
            'chapterTopics' => $chapterId ? $this->topicsForChapter($chapterId) : collect(),
            'topicsByChapter' => $chapters->mapWithKeys(fn ($chapter) => [
                $chapter['id'] => $this->topicsForChapter($chapter['id'])->values()->all(),
            ])->all(),
            'selectedChapterId' => $chapterId,
            'selectedTopicId' => $topicId,
            'scope' => $scope,
            'chapterPlan' => session('chapter_plan', []),
            'topic' => $topic,
            'selectedGrade' => $grade?->only(['id', 'name']),
            'cursorPrompt' => session('chapter_cursor_prompt') ?? $activePrompt,
            'promptOptions' => $promptOptions,
            'importMode' => $importMode,
            'extractedPreview' => $overrides['extractedPreview'] ?? null,
            'pdfFileName' => $overrides['pdfFileName'] ?? null,
            'pdfExtracted' => (bool) ($overrides['pdfExtracted'] ?? false),
            'pdfDirectParsed' => (bool) ($overrides['pdfDirectParsed'] ?? false),
            'initialImportRows' => $overrides['importRows'] ?? null,
            'pageError' => $overrides['error'] ?? null,
            'pdfImportToken' => $overrides['pdfImportToken'] ?? null,
            'referencePdfUrl' => $overrides['referencePdfUrl'] ?? null,
            'pdfPageCount' => $overrides['pdfPageCount'] ?? null,
            'pdfImportWarning' => $overrides['pdfImportWarning'] ?? null,
        ]);
    }

    private function isPdfUpload(?UploadedFile $file): bool
    {
        if (! $file) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        return $extension === 'pdf' || str_contains($mime, 'pdf');
    }

    public function chapterPrompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'plan' => ['required', 'array', 'min:1'],
            'plan.*.topic_id' => ['required', 'exists:syllabus_topics,id'],
            'plan.*.topic_name' => ['nullable', 'string'],
            'plan.*.easy' => ['nullable', 'integer', 'min:0'],
            'plan.*.medium' => ['nullable', 'integer', 'min:0'],
            'plan.*.hard' => ['nullable', 'integer', 'min:0'],
        ]);

        $chapter = SyllabusChapter::query()->findOrFail($validated['syllabus_chapter_id']);

        try {
            $prompt = $this->importService->cursorPromptForChapter($chapter, $validated['plan']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.questions.create', [
                'syllabus_chapter_id' => $chapter->id,
                'scope' => 'chapter',
            ])
            ->with('chapter_cursor_prompt', $prompt)
            ->with('chapter_plan', $validated['plan']);
    }

    public function storeBulkChapter(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'bank_purpose' => ['required', 'in:'.implode(',', QuestionBankPurpose::all())],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.syllabus_topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'rows.*.topic_name' => ['nullable', 'string'],
            'rows.*.question_text' => ['required', 'string'],
            'rows.*.explanation' => ['nullable', 'string'],
            'rows.*.method_hint' => ['nullable', 'string'],
            'rows.*.difficulty' => ['nullable', 'string', 'max:20'],
            'rows.*.options' => ['required', 'array', 'min:2'],
            'rows.*.options.*.option_text' => ['required', 'string'],
            'rows.*.options.*.is_correct' => ['boolean'],
        ]);

        $chapter = SyllabusChapter::query()
            ->with('topics')
            ->findOrFail($validated['syllabus_chapter_id']);

        try {
            $saved = DB::transaction(function () use ($validated, $request, $chapter) {
                return $this->importService->saveChapterRows(
                    $chapter,
                    $validated['rows'],
                    $request->user()->id,
                    Question::SOURCE_AI,
                    $validated['bank_purpose'],
                );
            });
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $confirmation = $this->saveConfirmation->build(
            $saved,
            $validated['bank_purpose'],
            $chapter,
        );

        return redirect()
            ->route('admin.questions.chapters.show', $chapter->id)
            ->with('success', count($saved).' question(s) saved successfully.')
            ->with('save_confirmation', $confirmation);
    }

    public function previewImport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'json' => ['required', 'string'],
        ]);

        try {
            $rows = $this->importService->parseJson($validated['json']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.questions.create', array_filter([
                'syllabus_topic_id' => $validated['syllabus_topic_id'],
                'syllabus_chapter_id' => SyllabusTopic::query()
                    ->whereKey($validated['syllabus_topic_id'])
                    ->value('syllabus_chapter_id'),
                'mode' => $request->string('mode')->toString() ?: null,
            ]))
            ->with('import_rows', $rows);
    }

    public function storeBulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'bank_purpose' => ['nullable', 'in:'.implode(',', QuestionBankPurpose::all())],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.question_text' => ['required', 'string'],
            'rows.*.explanation' => ['nullable', 'string'],
            'rows.*.method_hint' => ['nullable', 'string'],
            'rows.*.difficulty' => ['nullable', 'string', 'max:20'],
            'rows.*.options' => ['required', 'array', 'min:2'],
            'rows.*.options.*.option_text' => ['required', 'string'],
            'rows.*.options.*.is_correct' => ['boolean'],
            'diagrams' => ['nullable', 'array'],
            'diagrams.*' => ['nullable', 'image', 'max:5120'],
            'pdf_import_token' => ['nullable', 'uuid'],
        ]);

        $topic = SyllabusTopic::findOrFail($validated['syllabus_topic_id']);
        $diagramService = app(QuestionDiagramService::class);
        $pageService = app(PdfPageImageService::class);
        $worksheetImport = app(PdfWorksheetImportService::class);
        $pdfImport = $request->filled('pdf_import_token')
            ? $worksheetImport->pullImport($request->string('pdf_import_token')->toString())
            : null;

        $saved = DB::transaction(function () use ($validated, $request, $topic, $diagramService, $pdfImport, $pageService) {
            $saved = $this->importService->saveRows(
                $topic,
                $validated['rows'],
                $request->user()->id,
                Question::SOURCE_AI,
                QuestionBankPurpose::normalize($validated['bank_purpose'] ?? QuestionBankPurpose::PRACTICE_SET),
            );

            foreach ($saved as $index => $question) {
                if ($request->hasFile("diagrams.{$index}")) {
                    $diagramService->attach($question, $request->file("diagrams.{$index}"));
                } elseif ($pdfImport) {
                    $pageIndex = $pdfImport['page_assignments'][$index] ?? null;
                    if ($pageIndex !== null && isset($pdfImport['page_paths'][$pageIndex])) {
                        $diagramService->attachFromPath($question, $pdfImport['page_paths'][$pageIndex]);
                    }
                }
            }

            if ($pdfImport && ! empty($pdfImport['pdf_path'])) {
                $permanentPath = "topic-pdfs/{$topic->id}/worksheet.pdf";
                $pageService->copyToPermanent($pdfImport['pdf_path'], $permanentPath);
                $topic->update(['reference_pdf_path' => $permanentPath]);
            }

            if ($pdfImport && ! empty($pdfImport['token'])) {
                $pageService->deleteImportDirectory($pdfImport['token']);
            }

            return $saved;
        });

        $bankPurpose = QuestionBankPurpose::normalize($validated['bank_purpose'] ?? QuestionBankPurpose::PRACTICE_SET);
        $topic->load('chapter');

        $confirmation = $this->saveConfirmation->build(
            $saved,
            $bankPurpose,
            topic: $topic,
        );

        return redirect()
            ->route('admin.questions.chapters.show', $topic->syllabus_chapter_id)
            ->with('success', count($saved).' question(s) saved successfully.')
            ->with('save_confirmation', $confirmation);
    }

    public function edit(Question $question): Response
    {
        $question->load(['topic.chapter.syllabusVersion.gradeLevel', 'options']);

        return Inertia::render('Admin/Questions/Edit', [
            'question' => $question,
        ]);
    }

    public function update(Request $request, Question $question): RedirectResponse
    {
        $validated = $request->validate([
            'question_text' => ['required', 'string'],
            'explanation' => ['nullable', 'string'],
            'method_hint' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_text' => ['required', 'string'],
            'options.*.is_correct' => ['boolean'],
            'diagram' => ['nullable', 'image', 'max:5120'],
            'remove_diagram' => ['nullable', 'boolean'],
        ]);

        $this->importService->syncQuestion($question, $validated);

        $diagramService = app(QuestionDiagramService::class);

        if ($request->boolean('remove_diagram')) {
            $diagramService->deleteForQuestion($question);
        } elseif ($request->hasFile('diagram')) {
            $diagramService->attach($question, $request->file('diagram'));
        }

        return redirect()
            ->route('admin.questions.topics.show', $question->syllabus_topic_id)
            ->with('success', 'Question updated.');
    }

    public function destroy(Question $question): RedirectResponse
    {
        $topicId = $question->syllabus_topic_id;
        $question->delete();

        return redirect()
            ->route('admin.questions.topics.show', $topicId)
            ->with('success', 'Question deleted.');
    }

    private function chapterOptions(?int $gradeLevelId)
    {
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $activeYear || ! $maths) {
            return collect();
        }

        $query = SyllabusChapter::query()
            ->join('syllabus_versions', 'syllabus_versions.id', '=', 'syllabus_chapters.syllabus_version_id')
            ->join('grade_levels', 'grade_levels.id', '=', 'syllabus_versions.grade_level_id')
            ->join('boards', 'boards.id', '=', 'syllabus_versions.board_id')
            ->where('syllabus_versions.academic_year_id', $activeYear->id)
            ->where('syllabus_versions.subject_id', $maths->id)
            ->orderBy('grade_levels.sort_order')
            ->orderBy('syllabus_chapters.sort_order')
            ->select(
                'syllabus_chapters.id',
                'syllabus_chapters.chapter_number',
                'syllabus_chapters.name',
                'grade_levels.name as grade_name',
                'boards.code as board_code',
            );

        if ($gradeLevelId) {
            $query->where('syllabus_versions.grade_level_id', $gradeLevelId);
        }

        return $query->get()->map(fn ($chapter) => [
            'id' => $chapter->id,
            'label' => "{$chapter->board_code} {$chapter->grade_name} — Ch {$chapter->chapter_number} {$chapter->name}",
        ]);
    }

    private function topicsForChapter(int $chapterId)
    {
        return SyllabusTopic::query()
            ->where('syllabus_chapter_id', $chapterId)
            ->withCount('questions')
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn ($topic) => [
                'id' => $topic->id,
                'name' => $topic->name,
                'questions_count' => $topic->questions_count,
            ]);
    }
}
