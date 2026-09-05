<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\ConceptPathService;
use App\Services\GeminiFillBlankConversionService;
use App\Services\SetAssignmentService;
use App\Services\TextbookChapterAnswerClassificationService;
use App\Services\TextbookChapterBookService;
use App\Services\TextbookChapterConversionPromptService;
use App\Services\TextbookChapterFillBlankImportService;
use App\Services\TextbookChapterMcqImportService;
use App\Services\TextbookChapterMcqPromptService;
use App\Services\TextbookChapterPublishService;
use App\Services\TextbookChapterStagingGeminiService;
use App\Services\TextbookMcqSetPlanService;
use App\Services\TextbookSetCodeService;
use App\Support\UploadedFileDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TextbookController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private TextbookChapterPublishService $publishService,
        private TextbookChapterMcqPromptService $mcqPromptService,
        private TextbookChapterMcqImportService $mcqImportService,
        private TextbookChapterConversionPromptService $conversionPromptService,
        private TextbookChapterFillBlankImportService $fillBlankImportService,
        private TextbookSetCodeService $setCodeService,
        private TextbookMcqSetPlanService $setPlanService,
        private SetAssignmentService $assignmentService,
        private TextbookChapterBookService $bookService,
        private GeminiFillBlankConversionService $geminiFillBlank,
        private TextbookChapterStagingGeminiService $stagingGemini,
        private TextbookChapterAnswerClassificationService $answerClassification,
        private ConceptPathService $conceptPath,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $bookId = $request->integer('book_id') ?: null;

        $books = Textbook::query()
            ->when($gradeLevel, fn ($q) => $q->where('grade_level_id', $gradeLevel->id))
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Textbook $book) => [
                'id' => $book->id,
                'name' => $book->name,
                'code' => $book->code,
                'label' => trim($book->name.' ('.$book->code.')'),
            ])
            ->values()
            ->all();

        $chapters = TextbookChapter::query()
            ->with([
                'textbook:id,name,code,grade_level_id',
                'textbook.gradeLevel:id,name',
                'syllabusChapter:id,name,chapter_number',
                'mcqWorksheet:id,set_code',
                'fillBlankWorksheet:id,set_code',
                'writtenWorksheet:id,set_code',
            ])
            ->when($gradeLevel, fn ($q) => $q->whereHas(
                'textbook',
                fn ($inner) => $inner->where('grade_level_id', $gradeLevel->id),
            ))
            ->when($bookId, fn ($q) => $q->where('textbook_id', $bookId))
            ->join('textbooks', 'textbooks.id', '=', 'textbook_chapters.textbook_id')
            ->orderBy('textbooks.name')
            ->orderBy('textbook_chapters.chapter_number')
            ->select('textbook_chapters.*')
            ->get()
            ->each(fn (TextbookChapter $chapter) => $chapter->syncDisplayFromSyllabus())
            ->map(fn (TextbookChapter $chapter) => [
                'id' => $chapter->id,
                'textbook_id' => $chapter->textbook_id,
                'book_name' => $chapter->textbook?->name,
                'book_code' => $chapter->textbook?->code,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
                'chapter_number' => $chapter->displayChapterNumber(),
                'title' => $chapter->displayTitle(),
                'label' => $chapter->displaySyllabusLabel(),
                'status' => $chapter->status,
                'status_label' => $chapter->statusLabel(),
                'items_count' => count($chapter->extraction_items ?? []),
                'has_pdf' => filled($chapter->pdf_path),
                'mcq_set_code' => $chapter->mcqWorksheet?->set_code,
                'mcq_set_codes' => $this->publishedMcqSetCodes($chapter),
                'fill_blank_set_code' => $chapter->fillBlankWorksheet?->set_code,
                'written_set_code' => $chapter->writtenWorksheet?->set_code,
                'fill_blank_ready_count' => $this->fillBlankImportService->fillBlankReadyCount(
                    is_array($chapter->extraction_items) ? $chapter->extraction_items : [],
                ),
                'can_convert_fill_blank' => $chapter->status === TextbookChapter::STATUS_PUBLISHED
                    && count($chapter->extraction_items ?? []) > 0,
                'concept_path_status' => $chapter->concept_path_status,
                'concept_path_status_label' => \App\Support\ConceptPathStatus::label($chapter->concept_path_status),
                'published_at' => $chapter->published_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Textbooks/Index', [
            'chapters' => $chapters,
            'books' => $books,
            'filters' => [
                'book_id' => $bookId,
            ],
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $activeYear = AcademicYear::active();
        $chapters = [];
        $books = [];

        if ($gradeLevel && $activeYear) {
            $syllabus = SyllabusVersion::query()
                ->with(['chapters' => fn ($q) => $q->orderBy('sort_order')])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->first();

            $chapters = $syllabus?->chapters->map(fn (SyllabusChapter $chapter) => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
                'label' => self::chapterLabel($chapter),
            ])->values()->all() ?? [];

            $books = Textbook::query()
                ->where('grade_level_id', $gradeLevel->id)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->all();
        }

        return Inertia::render('Admin/Textbooks/Create', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'syllabusChapters' => $chapters,
            'books' => $books,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        abort_unless($gradeLevel, 422, 'Select a class from the top bar first.');

        $uploadedPdf = $request->file('pdf');
        if ($uploadedPdf) {
            UploadedFileDiagnostics::assertValid($uploadedPdf, 'pdf');
        }

        $validated = $request->validate([
            'book_name' => ['required', 'string', 'max:255'],
            'book_code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'syllabus_chapter_id' => ['required', 'integer', Rule::exists('syllabus_chapters', 'id')],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ], [
            'pdf.required' => 'Choose a chapter PDF file.',
            'pdf.mimes' => 'Only PDF files are allowed.',
            'pdf.max' => 'Each chapter PDF must be under 50 MB.',
            'pdf.uploaded' => 'The PDF is too large for the server upload limit. Set PHP upload_max_filesize and post_max_size to at least 20M on the server.',
        ]);

        $syllabusChapter = SyllabusChapter::query()->findOrFail($validated['syllabus_chapter_id']);
        $chapterNumber = $syllabusChapter->numericChapterNumber();

        $textbook = Textbook::query()->firstOrCreate(
            [
                'grade_level_id' => $gradeLevel->id,
                'code' => strtolower($validated['book_code']),
            ],
            [
                'name' => $validated['book_name'],
                'created_by' => $request->user()->id,
            ],
        );

        if ($textbook->name !== $validated['book_name']) {
            $textbook->update(['name' => $validated['book_name']]);
        }

        $existing = TextbookChapter::query()
            ->where('textbook_id', $textbook->id)
            ->where('syllabus_chapter_id', $syllabusChapter->id)
            ->first();

        if ($existing) {
            return redirect()
                ->route('admin.textbooks.show', $existing)
                ->with('error', 'This chapter is already uploaded for this book. Open it to re-extract or publish.');
        }

        $directory = 'textbooks/'.$textbook->id.'/chapters/'.$chapterNumber;
        $pdfPath = $request->file('pdf')->store($directory, 'public');

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => $chapterNumber,
            'title' => $syllabusChapter->name,
            'pdf_path' => $pdfPath,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('admin.textbooks.show', $chapter)
            ->with('success', 'Chapter PDF uploaded. Copy the AI prompt, generate MCQ JSON in Claude/Cursor/Gemini, then paste it below.');
    }

    public function show(Request $request, TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        try {
            return $this->renderChapterShow($request, $textbookChapter);
        } catch (\Throwable $e) {
            report($e);

            $message = 'Could not open this chapter ('.$e->getMessage().'). Try again or ask admin to check the server log.';

            if ($this->isContentUploaderContext($request)) {
                return redirect()
                    ->route('content.tasks.index')
                    ->with('error', $message);
            }

            return redirect()
                ->route('admin.textbooks.index')
                ->with('error', $message);
        }
    }

    private function renderChapterShow(Request $request, TextbookChapter $textbookChapter): Response
    {
        $textbookChapter->load([
            'textbook.gradeLevel',
            'syllabusChapter',
            'mcqWorksheet',
            'writtenWorksheet',
            'fillBlankWorksheet',
        ]);

        $textbookChapter->syncDisplayFromSyllabus();
        $textbookChapter->refresh()->load([
            'textbook.gradeLevel',
            'syllabusChapter',
            'mcqWorksheet',
            'writtenWorksheet',
            'fillBlankWorksheet',
        ]);

        $gradeLevel = $textbookChapter->textbook?->gradeLevel;
        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        $uploaderMode = $this->isContentUploaderContext($request);
        $activeYear = AcademicYear::active();

        try {
            $aiPrompt = $this->mcqPromptService->payload($textbookChapter);
        } catch (\Throwable $e) {
            report($e);
            $aiPrompt = [
                'prompt' => '',
                'sample_json' => '{}',
                'mcq_set_code' => '',
                'written_set_code' => '',
            ];
        }

        $rawItems = is_array($textbookChapter->extraction_items) ? $textbookChapter->extraction_items : [];
        try {
            $items = $this->mcqImportService->itemsWithDiagramPreviewUrls($rawItems);
        } catch (\Throwable $e) {
            report($e);
            $items = array_values(array_filter($rawItems, fn ($item) => is_array($item)));
        }
        $itemCount = count($items);

        $fillBlankConversion = null;
        if ($itemCount > 0) {
            try {
                // Skip embedding full mcq_reference_json (large chapters can OOM / 500).
                $fillBlankConversion = $this->conversionPromptService->payload($textbookChapter, false);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $fillBlankReadyCount = $this->fillBlankImportService->fillBlankReadyCount($items);
        $fillBlankCount = collect($items)->where('question_type', 'fill_blank')->count();
        $mcqCount = collect($items)->where('question_type', 'mcq')->count();
        $geminiVerifiedCount = collect($items)->where('gemini_verified', true)->count();
        $geminiPendingCount = collect($items)
            ->filter(fn (array $item) => ($item['approved'] ?? true)
                && trim((string) ($item['question_text'] ?? '')) !== ''
                && empty($item['gemini_verified']))
            ->count();
        $stagingGeminiPrompt = $itemCount > 0
            ? $this->stagingGemini->buildPrompt($items, $this->stagingGemini->chapterLabel($textbookChapter))
            : '';
        $storedPlan = is_array($textbookChapter->mcq_set_plan) ? $textbookChapter->mcq_set_plan : null;
        $mcqSetPlan = $storedPlan
            ?? ($itemCount > 0 ? $this->setPlanService->defaultPlan($textbookChapter, $itemCount) : []);

        $publishedSets = [];
        if (! $uploaderMode && $textbookChapter->status === TextbookChapter::STATUS_PUBLISHED) {
            $publishedSets = Worksheet::query()
                ->whereIn('id', $textbookChapter->mcqWorksheetIds())
                ->withCount('questions')
                ->orderBy('set_number')
                ->get()
                ->map(fn (Worksheet $worksheet) => [
                    'id' => $worksheet->id,
                    'set_code' => $worksheet->set_code,
                    'set_number' => $worksheet->set_number,
                    'questions_count' => $worksheet->questions_count,
                    'title' => $worksheet->title,
                    'assignments' => $this->assignmentService
                        ->assignmentsForWorksheet($worksheet->id)
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all();
        }

        $contentUploadTask = null;
        $taskQuery = ContentUploadTask::query()
            ->where('textbook_chapter_id', $textbookChapter->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED);

        if ($uploaderMode) {
            $taskQuery->where('assigned_to_user_id', $request->user()->id);
        } else {
            $taskQuery->with('assignee:id,name');
        }

        $task = $taskQuery->latest()->first();
        $hasPdf = false;
        try {
            $hasPdf = $this->bookService->hasStoredPdf($textbookChapter);
        } catch (\Throwable $e) {
            report($e);
        }

        if ($task) {
            if ($uploaderMode) {
                $contentUploadTask = [
                    'id' => $task->id,
                    'status' => $task->status,
                    'status_label' => $task->statusLabel(),
                    'bucket' => $task->uploaderBucket(),
                    'can_start_review' => $task->uploaderBucket() === 'review_pending',
                    'can_change_book' => $this->bookService->uploaderCanChangeBook($textbookChapter, $request->user()),
                    'has_pdf' => $hasPdf,
                ];
            } else {
                $contentUploadTask = [
                    'id' => $task->id,
                    'status' => $task->status,
                    'status_label' => $task->statusLabel(),
                    'assignee_name' => $task->assignee?->name,
                    'can_verify' => in_array($task->status, [
                        ContentUploadTask::STATUS_UPLOADED,
                        ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                        ContentUploadTask::STATUS_VERIFIED,
                        ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                        ContentUploadTask::STATUS_PUBLISHED,
                    ], true) && $textbookChapter->mcqWorksheetIds() !== [],
                    'can_change_book' => true,
                    'has_pdf' => $hasPdf,
                ];
            }
        }

        $gradeLevelId = (int) ($textbookChapter->textbook?->grade_level_id ?? 0);
        $pdfUrl = null;
        try {
            $pdfUrl = $textbookChapter->pdfUrl();
        } catch (\Throwable $e) {
            report($e);
        }

        return Inertia::render('Admin/Textbooks/Show', [
            'chapter' => [
                'id' => $textbookChapter->id,
                'status' => $textbookChapter->status,
                'status_label' => $textbookChapter->statusLabel(),
                'chapter_number' => $textbookChapter->displayChapterNumber(),
                'title' => $textbookChapter->displayTitle(),
                'label' => $textbookChapter->displaySyllabusLabel(),
                'pdf_url' => $pdfUrl,
                'has_pdf' => $hasPdf,
                'extraction_error' => $textbookChapter->extraction_error,
                'extracted_at' => $textbookChapter->extracted_at?->toDateTimeString(),
                'published_at' => $textbookChapter->published_at?->toDateTimeString(),
                'book' => [
                    'name' => $textbookChapter->textbook?->name,
                    'code' => $textbookChapter->textbook?->code,
                    'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                ],
                'syllabus_chapter_id' => $textbookChapter->syllabus_chapter_id,
                'syllabus_chapter_label' => $textbookChapter->syllabusChapter
                    ? self::chapterLabel($textbookChapter->syllabusChapter)
                    : null,
                'items' => $items,
                'mcq_set_plan' => $mcqSetPlan,
                'mcq_set_plan_summary' => $this->setPlanService->summary(is_array($mcqSetPlan) ? $mcqSetPlan : []),
                'mcq_worksheet_id' => $textbookChapter->mcq_worksheet_id,
                'mcq_worksheet_ids' => $textbookChapter->mcqWorksheetIds(),
                'written_worksheet_id' => $textbookChapter->written_worksheet_id,
                'fill_blank_worksheet_id' => $textbookChapter->fill_blank_worksheet_id,
                'mcq_set_code' => $textbookChapter->mcqWorksheet?->set_code ?? ($aiPrompt['mcq_set_code'] ?? null),
                'mcq_set_codes' => $this->publishedMcqSetCodes($textbookChapter),
                'written_set_code' => $textbookChapter->writtenWorksheet?->set_code ?? ($aiPrompt['written_set_code'] ?? null),
                'fill_blank_set_code' => $textbookChapter->fillBlankWorksheet?->set_code
                    ?? ($fillBlankConversion['fill_blank_set_code'] ?? null),
                'fill_blank_ready_count' => $fillBlankReadyCount,
                'fill_blank_count' => $fillBlankCount,
                'mcq_count' => $mcqCount,
                'gemini_verified_count' => $geminiVerifiedCount,
                'gemini_pending_count' => $geminiPendingCount,
                'concept_path_status' => $textbookChapter->concept_path_status,
                'concept_path_status_label' => \App\Support\ConceptPathStatus::label($textbookChapter->concept_path_status),
                'concept_path_card_count' => is_array($textbookChapter->concept_path_items['cards'] ?? null)
                    ? count($textbookChapter->concept_path_items['cards'])
                    : 0,
            ],
            'mcqImport' => $aiPrompt,
            'fillBlankConversion' => $fillBlankConversion,
            'stagingGemini' => [
                'prompt' => $stagingGeminiPrompt,
                'verified_count' => $geminiVerifiedCount,
                'pending_count' => $geminiPendingCount,
                'total_count' => $itemCount,
            ],
            'publishedSets' => $publishedSets,
            'students' => $uploaderMode
                ? []
                : $this->assignmentService
                    ->activeStudentsForAssignment($activeYear?->id)
                    ->values()
                    ->all(),
            'gradeLevels' => GradeLevel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->all(),
            'defaultGradeLevelId' => $gradeLevel?->id,
            'activeYear' => $activeYear?->only(['id', 'name']),
            'routeNamespace' => $uploaderMode ? 'content' : 'admin',
            'uploaderMode' => $uploaderMode,
            'contentUploadTask' => $contentUploadTask,
            'textbooks' => $gradeLevelId > 0 ? $this->bookService->textbooksForGrade($gradeLevelId) : [],
            'syllabusChaptersForRelink' => $uploaderMode
                ? []
                : $this->bookService->syllabusChaptersForRelink($textbookChapter),
        ]);
    }
    public function uploadPdf(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $uploadedPdf = $request->file('pdf');
        if ($uploadedPdf) {
            UploadedFileDiagnostics::assertValid($uploadedPdf, 'pdf');
        }

        $request->validate([
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ], [
            'pdf.required' => 'Choose a chapter PDF file.',
            'pdf.mimes' => 'Only PDF files are allowed.',
            'pdf.max' => 'Each chapter PDF must be under 50 MB.',
        ]);

        try {
            $this->bookService->uploadPdf(
                $textbookChapter,
                $uploadedPdf,
                $request->user(),
                $this->isContentUploaderContext($request),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToChapterShow($textbookChapter->fresh())
            ->with('success', 'Chapter PDF saved.');
    }

    public function changeBook(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $validated = $request->validate([
            'textbook_id' => ['nullable', 'integer', Rule::exists('textbooks', 'id')],
            'book_name' => ['required_without:textbook_id', 'nullable', 'string', 'max:255'],
            'book_code' => ['required_without:textbook_id', 'nullable', 'string', 'max:32', 'alpha_dash'],
        ]);

        try {
            $chapter = $this->bookService->changeBook(
                $textbookChapter,
                $request->user(),
                $validated['textbook_id'] ?? null,
                $validated['book_name'] ?? null,
                $validated['book_code'] ?? null,
                ! $this->isContentUploaderContext($request),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToChapterShow($chapter)
            ->with('success', 'Book updated to '.$chapter->textbook?->name.'.');
    }

    public function changeSyllabusChapter(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_if($this->isContentUploaderContext($request), 403);

        $validated = $request->validate([
            'syllabus_chapter_id' => ['required', 'integer', Rule::exists('syllabus_chapters', 'id')],
        ]);

        try {
            $chapter = $this->bookService->changeSyllabusChapter(
                $textbookChapter,
                (int) $validated['syllabus_chapter_id'],
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $label = $chapter->syllabusChapter
            ? self::chapterLabel($chapter->syllabusChapter)
            : 'the selected chapter';

        return $this->redirectToChapterShow($chapter)
            ->with('success', 'MCQ bank moved to '.$label.'. Students on that board/class heading can use the same questions.');
    }

    public function importMcq(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        if ($redirect = $this->rejectLockedUploaderReplace($request, $textbookChapter)) {
            return $redirect;
        }

        $validated = $request->validate([
            'json' => ['required', 'string'],
        ]);

        try {
            $chapter = $this->mcqImportService->import($textbookChapter, $validated['json']);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $count = count($chapter->extraction_items ?? []);

        return $this->redirectToChapterShow($chapter)
            ->with('success', "{$count} question(s) imported (fill-blank + MCQ mix). Review figures, run Gemini check, then publish.");
    }

    public function stagingGeminiPaste(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $validated = $request->validate([
            'gemini_paste' => ['required', 'string'],
        ]);

        try {
            $result = $this->stagingGemini->applyPaste($textbookChapter, $validated['gemini_paste']);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToChapterShow($textbookChapter->fresh())
            ->with('staging_gemini_review', $result)
            ->with('success', "Gemini review applied: {$result['approved']} verified, {$result['needs_attention']} need attention.");
    }

    public function resetStagingGeminiReview(TextbookChapter $textbookChapter): RedirectResponse
    {
        $this->stagingGemini->resetGeminiReview($textbookChapter);

        return $this->redirectToChapterShow($textbookChapter->fresh())
            ->with('success', 'Gemini review reset — you can check all questions again.');
    }

    public function importMcqZip(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        if ($redirect = $this->rejectLockedUploaderReplace($request, $textbookChapter)) {
            return $redirect;
        }

        $uploadedZip = $request->file('pack');
        if ($uploadedZip) {
            UploadedFileDiagnostics::assertValid($uploadedZip, 'pack');
        }

        $request->validate([
            'pack' => ['required', 'file', 'mimes:zip', 'max:51200'],
        ], [
            'pack.required' => 'Choose a .zip file with questions.json and chart images.',
            'pack.mimes' => 'Only .zip files are allowed.',
            'pack.max' => 'The zip must be under 50 MB.',
        ]);

        try {
            $result = $this->mcqImportService->importZip($textbookChapter, $uploadedZip);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = "{$result['question_count']} MCQ(s) imported from zip.";
        if ($result['diagram_count'] > 0) {
            $message .= " {$result['diagram_count']} chart/diagram image(s) linked.";
        }
        $message .= ' Edit the set plan matrix below, then Publish.';

        return $this->redirectToChapterShow($result['chapter'])
            ->with('success', $message);
    }

    public function replaceItemDiagram(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(in_array($textbookChapter->status, [
            TextbookChapter::STATUS_REVIEW,
            TextbookChapter::STATUS_PUBLISHED,
            TextbookChapter::STATUS_FAILED,
        ], true), 422);

        $uploaded = $request->file('diagram');
        if ($uploaded) {
            UploadedFileDiagnostics::assertValid($uploaded, 'diagram');
        }

        $validated = $request->validate([
            'item_index' => ['required', 'integer', 'min:0'],
            'diagram' => ['required', 'image', 'max:5120'],
        ], [
            'diagram.required' => 'Choose a PNG or JPG chart image.',
            'diagram.max' => 'Chart image must be under 5 MB.',
        ]);

        try {
            $this->mcqImportService->replaceItemDiagram(
                $textbookChapter,
                (int) $validated['item_index'],
                $uploaded,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->redirectToChapterShow($textbookChapter)
                ->with('error', $exception->getMessage());
        }

        $label = ($textbookChapter->fresh()->extraction_items[$validated['item_index']]['label'] ?? null)
            ?: 'Q'.((int) $validated['item_index'] + 1);

        return $this->redirectToChapterShow($textbookChapter)
            ->with('success', "Chart updated for {$label}. Students see the new image immediately if sets are already published.");
    }

    public function removeItemDiagram(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(in_array($textbookChapter->status, [
            TextbookChapter::STATUS_REVIEW,
            TextbookChapter::STATUS_PUBLISHED,
            TextbookChapter::STATUS_FAILED,
        ], true), 422);

        $validated = $request->validate([
            'item_index' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $this->mcqImportService->removeItemDiagram($textbookChapter, (int) $validated['item_index']);
        } catch (\InvalidArgumentException $exception) {
            return $this->redirectToChapterShow($textbookChapter)
                ->with('error', $exception->getMessage());
        }

        return $this->redirectToChapterShow($textbookChapter)
            ->with('success', 'Chart removed for this question.');
    }

    public function updateDraft(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(in_array($textbookChapter->status, [
            TextbookChapter::STATUS_REVIEW,
            TextbookChapter::STATUS_PUBLISHED,
            TextbookChapter::STATUS_FAILED,
        ], true), 422);

        try {
            if ($request->filled('items_json')) {
                $decoded = json_decode((string) $request->input('items_json'), true);
                if (! is_array($decoded) || $decoded === []) {
                    throw new \InvalidArgumentException('Invalid draft payload.');
                }
                $items = array_values(array_filter($decoded, fn ($item) => is_array($item)));
                $setPlanInput = $request->input('mcq_set_plan', $textbookChapter->mcq_set_plan ?? []);
            } else {
                $validated = $request->validate([
                    'items' => ['required', 'array', 'min:1'],
                    'mcq_set_plan' => ['nullable', 'array'],
                ]);
                $items = $validated['items'];
                $setPlanInput = $validated['mcq_set_plan'] ?? $textbookChapter->mcq_set_plan ?? [];
            }

            $setPlan = $this->setPlanService->normalizePlanRows(
                is_array($setPlanInput) ? $setPlanInput : [],
                $textbookChapter,
                count($items),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $textbookChapter->update([
            'extraction_items' => $items,
            'mcq_set_plan' => $setPlan,
            // Keep published chapters published so Save draft can update the split plan
            // without hiding assign/re-publish actions.
            'status' => $textbookChapter->status === TextbookChapter::STATUS_PUBLISHED
                ? TextbookChapter::STATUS_PUBLISHED
                : TextbookChapter::STATUS_REVIEW,
        ]);

        return back()->with('success', $textbookChapter->status === TextbookChapter::STATUS_PUBLISHED
            ? 'Draft saved. Click Re-publish MCQ sets to apply the set plan split.'
            : 'Draft saved.');
    }

    public function reclassifyStagingItem(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(in_array($textbookChapter->status, [
            TextbookChapter::STATUS_REVIEW,
            TextbookChapter::STATUS_PUBLISHED,
            TextbookChapter::STATUS_FAILED,
        ], true), 422);

        $validated = $request->validate([
            'item_index' => ['required', 'integer', 'min:0'],
            'target' => ['required', 'in:fill_blank,mcq'],
        ]);

        $items = $textbookChapter->extraction_items ?? [];
        $index = (int) $validated['item_index'];

        if (! isset($items[$index])) {
            return back()->with('error', 'Question not found.');
        }

        try {
            $items[$index] = $validated['target'] === 'fill_blank'
                ? $this->answerClassification->convertItemToFillBlank($items[$index])
                : $this->answerClassification->revertItemToMcq($items[$index]);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $textbookChapter->update(['extraction_items' => array_values($items)]);

        return back()->with(
            'success',
            $validated['target'] === 'fill_blank'
                ? 'Converted to fill-in-blank. Save draft when done reviewing.'
                : 'Reverted to MCQ. Save draft when done reviewing.',
        );
    }

    public function publish(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        if ($redirect = $this->rejectLockedUploaderReplace($request, $textbookChapter)) {
            return $redirect;
        }

        if ($this->isContentUploaderContext($request) && ! $this->bookService->hasStoredPdf($textbookChapter)) {
            return back()->with('error', 'Upload the chapter PDF before saving MCQ sets.');
        }

        try {
            [$items, $setPlan] = $this->resolvePublishPayload($request, $textbookChapter);
            $this->publishService->publish($textbookChapter, $items, $request->user(), $setPlan);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with(
                'error',
                'Could not save MCQ sets ('.$exception->getMessage().'). '
                .'If the chapter is large, click Save draft once, then try Save MCQ sets again. Ask admin to check the server log if it keeps failing.',
            );
        }

        $textbookChapter->refresh();
        $summary = $this->setPlanService->summary($textbookChapter->mcq_set_plan ?? []);

        $message = $this->isContentUploaderContext($request)
            ? "MCQ sets saved: {$summary}. Click Review & complete to verify each question."
            : "Published — MCQ sets ready: {$summary}.";

        return $this->redirectToChapterShow($textbookChapter)
            ->with('success', $message);
    }

    /**
     * Large chapters cannot POST every item field (PHP max_input_vars truncates → 500).
     * Prefer items already stored on the chapter; accept set plan (and optional items_json).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null}
     */
    private function resolvePublishPayload(Request $request, TextbookChapter $textbookChapter): array
    {
        $storedItems = array_values(array_filter(
            is_array($textbookChapter->extraction_items) ? $textbookChapter->extraction_items : [],
            fn ($item) => is_array($item),
        ));

        $items = $storedItems;
        $setPlan = $textbookChapter->mcq_set_plan;

        if ($request->filled('items_json')) {
            $decoded = json_decode((string) $request->input('items_json'), true);
            if (! is_array($decoded)) {
                throw new \InvalidArgumentException('Invalid items_json payload.');
            }
            $items = array_values(array_filter($decoded, fn ($item) => is_array($item)));
        } elseif ($request->has('items') && is_array($request->input('items'))) {
            $posted = array_values(array_filter($request->input('items'), fn ($item) => is_array($item)));
            // Small chapters can still post items; large posts are usually truncated — keep DB copy.
            if ($posted !== [] && count($posted) <= 25) {
                $items = $posted;
            } elseif ($posted !== [] && count($posted) === count($storedItems)) {
                $items = $posted;
            } elseif ($storedItems === []) {
                $items = $posted;
            }
        }

        if ($items === []) {
            throw new \InvalidArgumentException('Import and approve MCQs before saving sets.');
        }

        if ($request->has('mcq_set_plan')) {
            $setPlan = $request->input('mcq_set_plan');
        }

        return [$items, is_array($setPlan) ? $setPlan : null];
    }

    public function importFillBlank(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(count($textbookChapter->extraction_items ?? []) > 0, 422, 'Import MCQs first.');

        $validated = $request->validate([
            'json' => ['required', 'string'],
        ]);

        try {
            $result = $this->fillBlankImportService->import($textbookChapter, $validated['json']);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToChapterShow($textbookChapter->fresh())
            ->with('success', "{$result['merged_count']} fill-in-blank question(s) merged ({$result['total_mcq']} MCQs in chapter). Publish fill-blank + written when ready.");
    }

    public function publishFillBlankAndWritten(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        try {
            $chapter = $this->publishService->publishFillBlankAndWritten($textbookChapter, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $codes = $this->setCodeService->codes($chapter);

        return $this->redirectToChapterShow($chapter)
            ->with('success', "Published online fill-blank {$codes['fill_blank']} and written {$codes['written']}. MCQ sets unchanged.");
    }

    public function conceptPath(Request $request, TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        if (! $textbookChapter->pdf_path) {
            return $this->redirectToChapterShow($textbookChapter)
                ->with('error', 'Upload the chapter PDF first, then build the concept path.');
        }

        $textbookChapter->load([
            'textbook.gradeLevel:id,name',
            'textbook.board:id,code,name',
            'syllabusChapter:id,name,chapter_number',
        ]);

        $uploaderMode = $this->isContentUploaderContext($request);

        return Inertia::render('Admin/Textbooks/ConceptPath', [
            'uploaderMode' => $uploaderMode,
            'chapter' => [
                'id' => $textbookChapter->id,
                'chapter_number' => $textbookChapter->displayChapterNumber(),
                'title' => $textbookChapter->displayTitle(),
                'label' => $textbookChapter->displaySyllabusLabel(),
                'book_name' => $textbookChapter->textbook?->name,
                'book_code' => $textbookChapter->textbook?->code,
                'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                'has_pdf' => filled($textbookChapter->pdf_path),
                'pdf_url' => $textbookChapter->pdfUrl(),
                'show_url' => $uploaderMode
                    ? route('content.textbooks.show', $textbookChapter)
                    : route('admin.textbooks.show', $textbookChapter),
                'download_url' => $uploaderMode
                    ? route('content.textbooks.download', $textbookChapter)
                    : route('admin.textbooks.download', $textbookChapter),
            ],
            'conceptPath' => $this->conceptPath->payload($textbookChapter),
            'routes' => [
                'preview' => $uploaderMode
                    ? route('content.textbooks.concept-path.preview', $textbookChapter)
                    : route('admin.textbooks.concept-path.preview', $textbookChapter),
                'save' => $uploaderMode
                    ? route('content.textbooks.concept-path.save', $textbookChapter)
                    : route('admin.textbooks.concept-path.save', $textbookChapter),
                'approve' => $uploaderMode
                    ? route('content.textbooks.concept-path.approve', $textbookChapter)
                    : route('admin.textbooks.concept-path.approve', $textbookChapter),
                'reset' => $uploaderMode
                    ? route('content.textbooks.concept-path.reset', $textbookChapter)
                    : route('admin.textbooks.concept-path.reset', $textbookChapter),
            ],
        ]);
    }

    public function previewConceptPath(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $validated = $request->validate([
            'json' => ['required', 'string'],
        ]);

        $preview = $this->conceptPath->preview($validated['json']);

        if ($preview['error']) {
            return back()
                ->withInput()
                ->with('error', $preview['error']);
        }

        return back()
            ->withInput()
            ->with('concept_path_preview', $preview)
            ->with('success', count($preview['cards']).' concept cards ready to review.');
    }

    public function saveConceptPath(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $validated = $request->validate([
            'chapter_title' => ['nullable', 'string', 'max:200'],
            'cards' => ['required', 'array', 'min:1'],
            'cards.*.step' => ['nullable', 'integer', 'min:1'],
            'cards.*.type' => ['required', 'string', Rule::in(['teach', 'check'])],
            'cards.*.title' => ['required', 'string', 'max:120'],
            'cards.*.topic' => ['nullable', 'string', 'max:200'],
            'cards.*.body' => ['nullable', 'string', 'max:2000'],
            'cards.*.example' => ['nullable', 'string', 'max:800'],
            'cards.*.common_mistake' => ['nullable', 'string', 'max:500'],
            'cards.*.approved' => ['nullable', 'boolean'],
            'cards.*.questions' => ['nullable', 'array'],
        ]);

        try {
            $this->conceptPath->saveDraft(
                $textbookChapter,
                $validated['cards'],
                $validated['chapter_title'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Concept path saved as draft. Review cards, then approve when the flow looks good.');
    }

    public function approveConceptPath(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        try {
            $this->conceptPath->approve($textbookChapter, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->redirectToChapterShow($textbookChapter)
            ->with('success', 'Concept path approved. Student player can be wired next.');
    }

    public function resetConceptPath(TextbookChapter $textbookChapter): RedirectResponse
    {
        $this->conceptPath->reset($textbookChapter);

        return back()->with('success', 'Concept path cleared. Generate a new Cursor prompt when ready.');
    }

    public function convertGemini(TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        abort_unless(
            $textbookChapter->status === TextbookChapter::STATUS_PUBLISHED,
            404,
            'Publish MCQs first before fill-in-blank conversion.',
        );
        abort_unless(count($textbookChapter->extraction_items ?? []) > 0, 404, 'Import MCQs first.');

        $textbookChapter->load([
            'textbook.gradeLevel:id,name',
            'syllabusChapter:id,name,chapter_number',
        ]);

        try {
            $gemini = $this->geminiFillBlank->payload($textbookChapter);
        } catch (\InvalidArgumentException $exception) {
            return redirect()
                ->route('admin.textbooks.show', $textbookChapter)
                ->with('error', $exception->getMessage());
        }

        $items = is_array($textbookChapter->extraction_items) ? $textbookChapter->extraction_items : [];

        return Inertia::render('Admin/Textbooks/ConvertGemini', [
            'chapter' => [
                'id' => $textbookChapter->id,
                'chapter_number' => $textbookChapter->chapter_number,
                'title' => $textbookChapter->title,
                'book_name' => $textbookChapter->textbook?->name,
                'book_code' => $textbookChapter->textbook?->code,
                'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                'items_count' => count($items),
                'fill_blank_ready_count' => $this->fillBlankImportService->fillBlankReadyCount($items),
                'fill_blank_set_code' => $textbookChapter->fillBlankWorksheet?->set_code
                    ?? ($gemini['fill_blank_set_code'] ?? null),
            ],
            'gemini' => $gemini,
        ]);
    }

    public function previewGeminiConversion(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(count($textbookChapter->extraction_items ?? []) > 0, 422, 'Import MCQs first.');

        $validated = $request->validate([
            'json' => ['required', 'string', 'min:20'],
        ]);

        try {
            $preview = $this->geminiFillBlank->preview($textbookChapter, $validated['json']);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()
            ->with('conversion_gemini_preview', $preview)
            ->with('conversion_gemini_json', $validated['json']);
    }

    public function applyGeminiConversion(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(count($textbookChapter->extraction_items ?? []) > 0, 422, 'Import MCQs first.');

        $validated = $request->validate([
            'json' => ['required', 'string', 'min:20'],
        ]);

        try {
            $result = $this->geminiFillBlank->applyForChapter($textbookChapter, $validated['json']);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.textbooks.convert-gemini', $textbookChapter)
            ->with('success', sprintf(
                'Applied Gemini conversion: %d fill-in-blank ready, %d stay MCQ-only in this set.',
                $result['convertible_count'],
                $result['not_possible_count'],
            ));
    }

    public function downloadMcqReference(TextbookChapter $textbookChapter)
    {
        abort_unless(count($textbookChapter->extraction_items ?? []) > 0, 422, 'Import MCQs first.');

        $reference = $this->conversionPromptService->mcqReference(
            $textbookChapter,
            array_values(array_filter(
                is_array($textbookChapter->extraction_items) ? $textbookChapter->extraction_items : [],
                fn ($item) => is_array($item),
            )),
        );

        $filename = sprintf(
            'mcq-reference-ch%02d.json',
            $textbookChapter->chapter_number,
        );

        return response()->streamDownload(
            fn () => print (json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    public function resetImport(TextbookChapter $textbookChapter): RedirectResponse
    {
        if ($redirect = $this->rejectLockedUploaderReplace(request(), $textbookChapter)) {
            return $redirect;
        }

        $this->mcqImportService->deleteStagingDiagrams($textbookChapter);

        $textbookChapter->update([
            'status' => TextbookChapter::STATUS_DRAFT,
            'extraction_items' => null,
            'mcq_set_plan' => null,
            'extraction_error' => null,
            'extracted_at' => null,
        ]);

        return back()->with('success', 'Cleared imported MCQs — paste fresh JSON to import again.');
    }

    public function download(TextbookChapter $textbookChapter)
    {
        abort_unless(Storage::disk('public')->exists($textbookChapter->pdf_path), 404);

        return Storage::disk('public')->download(
            $textbookChapter->pdf_path,
            ($textbookChapter->textbook?->code ?: 'textbook').'-ch'.$textbookChapter->chapter_number.'.pdf',
        );
    }

    private static function chapterLabel(SyllabusChapter $chapter): string
    {
        $name = trim($chapter->name);

        if (preg_match('/^Ch\s*\d+/i', $name)) {
            return $name;
        }

        $number = preg_replace('/^Ch\s*/i', '', trim((string) $chapter->chapter_number));
        $number = ltrim($number, '0') ?: $number;

        return "Ch {$number} — {$name}";
    }

    /**
     * @return list<string>
     */
    private function publishedMcqSetCodes(TextbookChapter $chapter): array
    {
        if ($chapter->status !== TextbookChapter::STATUS_PUBLISHED) {
            return [];
        }

        $ids = $chapter->mcqWorksheetIds();
        if ($ids === []) {
            return [];
        }

        return Worksheet::query()
            ->whereIn('id', $ids)
            ->orderBy('set_number')
            ->pluck('set_code')
            ->filter()
            ->values()
            ->all();
    }

    private function isContentUploaderContext(?Request $request = null): bool
    {
        $request ??= request();

        return $request->routeIs('content.*');
    }

    private function rejectLockedUploaderReplace(Request $request, TextbookChapter $textbookChapter): ?RedirectResponse
    {
        if (! $this->isContentUploaderContext($request)) {
            return null;
        }

        $task = ContentUploadTask::query()
            ->where('textbook_chapter_id', $textbookChapter->id)
            ->where('assigned_to_user_id', $request->user()?->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->latest('id')
            ->first();

        if (! $task?->isLockedForUploaderDelete()) {
            return null;
        }

        return back()->with(
            'error',
            'This chapter is already submitted. Use My chapters to add more questions, or ask admin to delete one.',
        );
    }

    private function redirectToChapterShow(TextbookChapter $chapter): RedirectResponse
    {
        $route = $this->isContentUploaderContext()
            ? 'content.textbooks.show'
            : 'admin.textbooks.show';

        return redirect()->route($route, $chapter);
    }
}
