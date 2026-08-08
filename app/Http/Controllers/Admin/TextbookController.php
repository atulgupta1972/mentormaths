<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\GradeLevel;
use App\Models\Textbook;
use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\SetAssignmentService;
use App\Services\TextbookChapterMcqImportService;
use App\Services\TextbookChapterMcqPromptService;
use App\Services\TextbookChapterPublishService;
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
        private TextbookSetCodeService $setCodeService,
        private TextbookMcqSetPlanService $setPlanService,
        private SetAssignmentService $assignmentService,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        $chapters = TextbookChapter::query()
            ->with([
                'textbook:id,name,code,grade_level_id',
                'textbook.gradeLevel:id,name',
                'syllabusChapter:id,name,chapter_number',
                'mcqWorksheet:id,set_code',
                'writtenWorksheet:id,set_code',
            ])
            ->when($gradeLevel, fn ($q) => $q->whereHas(
                'textbook',
                fn ($inner) => $inner->where('grade_level_id', $gradeLevel->id),
            ))
            ->orderByDesc('id')
            ->get()
            ->map(fn (TextbookChapter $chapter) => [
                'id' => $chapter->id,
                'book_name' => $chapter->textbook?->name,
                'book_code' => $chapter->textbook?->code,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'status' => $chapter->status,
                'status_label' => $chapter->statusLabel(),
                'items_count' => count($chapter->extraction_items ?? []),
                'mcq_set_code' => $chapter->mcqWorksheet?->set_code,
                'mcq_set_codes' => $this->publishedMcqSetCodes($chapter),
                'written_set_code' => $chapter->writtenWorksheet?->set_code,
                'published_at' => $chapter->published_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Textbooks/Index', [
            'chapters' => $chapters,
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

    public function show(Request $request, TextbookChapter $textbookChapter): Response
    {
        $textbookChapter->load([
            'textbook.gradeLevel',
            'syllabusChapter',
            'mcqWorksheet',
            'writtenWorksheet',
        ]);

        $gradeLevel = $textbookChapter->textbook?->gradeLevel;
        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        $activeYear = AcademicYear::active();
        $aiPrompt = $this->mcqPromptService->payload($textbookChapter);
        $itemCount = count($textbookChapter->extraction_items ?? []);
        $mcqSetPlan = $textbookChapter->mcq_set_plan
            ?? ($itemCount > 0 ? $this->setPlanService->defaultPlan($textbookChapter, $itemCount) : []);

        $publishedSets = [];
        if ($textbookChapter->status === TextbookChapter::STATUS_PUBLISHED) {
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

        if ($this->isContentUploaderContext($request)) {
            $taskQuery->where('assigned_to_user_id', $request->user()->id);
        } else {
            $taskQuery->with('assignee:id,name');
        }

        $task = $taskQuery->latest()->first();

        if ($task) {
            if ($this->isContentUploaderContext($request)) {
                $contentUploadTask = [
                    'id' => $task->id,
                    'status' => $task->status,
                    'status_label' => $task->statusLabel(),
                    'bucket' => $task->uploaderBucket(),
                    'can_start_review' => $task->uploaderBucket() === 'review_pending',
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
                ];
            }
        }

        return Inertia::render('Admin/Textbooks/Show', [
            'chapter' => [
                'id' => $textbookChapter->id,
                'status' => $textbookChapter->status,
                'status_label' => $textbookChapter->statusLabel(),
                'chapter_number' => $textbookChapter->chapter_number,
                'title' => $textbookChapter->title,
                'pdf_url' => $textbookChapter->pdfUrl(),
                'extraction_error' => $textbookChapter->extraction_error,
                'extracted_at' => $textbookChapter->extracted_at?->toDateTimeString(),
                'published_at' => $textbookChapter->published_at?->toDateTimeString(),
                'book' => [
                    'name' => $textbookChapter->textbook?->name,
                    'code' => $textbookChapter->textbook?->code,
                    'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                ],
                'items' => $this->mcqImportService->itemsWithDiagramPreviewUrls($textbookChapter->extraction_items ?? []),
                'mcq_set_plan' => $mcqSetPlan,
                'mcq_set_plan_summary' => $this->setPlanService->summary($mcqSetPlan),
                'mcq_worksheet_id' => $textbookChapter->mcq_worksheet_id,
                'mcq_worksheet_ids' => $textbookChapter->mcqWorksheetIds(),
                'written_worksheet_id' => $textbookChapter->written_worksheet_id,
                'mcq_set_code' => $textbookChapter->mcqWorksheet?->set_code ?? $aiPrompt['mcq_set_code'],
                'mcq_set_codes' => $this->publishedMcqSetCodes($textbookChapter),
                'written_set_code' => $textbookChapter->writtenWorksheet?->set_code ?? $aiPrompt['written_set_code'],
            ],
            'mcqImport' => $aiPrompt,
            'publishedSets' => $publishedSets,
            'students' => $this->assignmentService
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
            'routeNamespace' => $this->isContentUploaderContext($request) ? 'content' : 'admin',
            'uploaderMode' => $this->isContentUploaderContext($request),
            'contentUploadTask' => $contentUploadTask,
        ]);
    }

    public function importMcq(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
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
            ->with('success', "{$count} MCQ(s) imported. Edit the set plan matrix below — small chapters: keep one row for all questions.");
    }

    public function importMcqZip(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
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

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'mcq_set_plan' => ['nullable', 'array'],
        ]);

        $itemCount = count($validated['items']);

        try {
            $setPlan = $this->setPlanService->normalizePlanRows(
                $validated['mcq_set_plan'] ?? $textbookChapter->mcq_set_plan ?? [],
                $textbookChapter,
                $itemCount,
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $textbookChapter->update([
            'extraction_items' => $validated['items'],
            'mcq_set_plan' => $setPlan,
            'status' => TextbookChapter::STATUS_REVIEW,
        ]);

        return back()->with('success', 'Draft saved.');
    }

    public function publish(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $items = $textbookChapter->extraction_items ?? [];
        $setPlan = $textbookChapter->mcq_set_plan;

        if ($request->has('items')) {
            $validated = $request->validate([
                'items' => ['required', 'array', 'min:1'],
                'mcq_set_plan' => ['nullable', 'array'],
            ]);
            $items = $validated['items'];
            $setPlan = $validated['mcq_set_plan'] ?? $setPlan;
        }

        try {
            $this->publishService->publish($textbookChapter, $items, $request->user(), $setPlan);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $textbookChapter->refresh();
        $summary = $this->setPlanService->summary($textbookChapter->mcq_set_plan ?? []);

        $message = $this->isContentUploaderContext($request)
            ? "MCQ sets saved: {$summary}. Click Review & complete to verify each question."
            : "Published — MCQ sets ready: {$summary}.";

        return $this->redirectToChapterShow($textbookChapter)
            ->with('success', $message);
    }

    public function resetImport(TextbookChapter $textbookChapter): RedirectResponse
    {
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

    private function redirectToChapterShow(TextbookChapter $chapter): RedirectResponse
    {
        $route = $this->isContentUploaderContext()
            ? 'content.textbooks.show'
            : 'admin.textbooks.show';

        return redirect()->route($route, $chapter);
    }
}
