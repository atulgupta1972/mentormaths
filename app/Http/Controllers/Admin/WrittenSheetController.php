<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\FillBlankImportService;
use App\Services\QuestionZipImportService;
use App\Services\SetAssignmentService;
use App\Services\WrittenSheetPdfImportService;
use App\Services\WrittenSheetService;
use App\Services\WrittenSheetAnswerKeyParser;
use App\Services\WrittenSubmissionService;
use App\Support\DiagramQuestionSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WrittenSheetController extends Controller
{
    public function __construct(
        private WrittenSheetService $writtenSheetService,
        private AdminGradeContext $gradeContext,
        private FillBlankImportService $fillBlankImportService,
        private SetAssignmentService $assignmentService,
        private QuestionZipImportService $zipImportService,
        private WrittenSheetPdfImportService $pdfImportService,
        private WrittenSubmissionService $submissionService,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        return Inertia::render('Admin/WrittenSheets/Index', [
            'sheets' => $this->writtenSheetService->listForAdmin($gradeLevel?->id)->values()->all(),
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $activeYear = AcademicYear::active();
        $chapters = [];

        if ($gradeLevel && $activeYear) {
            $syllabus = SyllabusVersion::query()
                ->with(['chapters' => fn ($q) => $q->orderBy('sort_order')])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->first();

            $chapters = $syllabus?->chapters->map(fn (SyllabusChapter $chapter) => [
                'id' => $chapter->id,
                'label' => "Ch {$chapter->chapter_number} — {$chapter->name}",
            ])->values()->all() ?? [];
        }

        $chapterId = $request->integer('chapter_id') ?: null;
        $topicId = $request->integer('topic_id') ?: null;
        $sheetKind = $request->string('sheet_kind')->toString() ?: 'practice';
        $topicScope = $request->string('topic_scope')->toString() === 'multiple' ? 'multiple' : 'one';
        $topicIds = collect($request->input('topic_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
        $topics = [];
        $questions = [];
        $chapter = null;

        if ($chapterId) {
            $chapter = SyllabusChapter::query()->with('topics')->find($chapterId);
            $topics = $chapter?->topics->map(fn ($topic) => [
                'id' => $topic->id,
                'name' => $topic->name,
            ])->values()->all() ?? [];

            $validTopicIds = collect($topics)->pluck('id')->all();

            if ($topicScope === 'multiple') {
                $topicIds = array_values(array_intersect($topicIds, $validTopicIds));
                if ($topicIds === [] && $validTopicIds !== []) {
                    $topicIds = $validTopicIds;
                }
            } elseif ($topicId) {
                $topicIds = in_array($topicId, $validTopicIds, true) ? [$topicId] : [];
            }

            $query = Question::query()->with('topic:id,name');

            if ($sheetKind === 'practice' && $topicScope === 'one' && $topicId) {
                $query->where('syllabus_topic_id', $topicId);
            } elseif ($sheetKind === 'practice' && $topicScope === 'multiple' && $topicIds !== []) {
                $query->whereIn('syllabus_topic_id', $topicIds);
            } else {
                $query->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapterId));
            }

            $questions = $query->orderBy('id')->get()->map(fn (Question $question) => [
                'id' => $question->id,
                'topic_name' => $question->topic?->name,
                'type' => $question->type,
                'question_text' => strip_tags((string) $question->question_text),
                'has_diagram' => (bool) $question->diagram_path,
            ])->values()->all();
        }

        $selectedQuestionIds = collect($request->input('question_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if ($selectedQuestionIds !== [] && $questions !== []) {
            $validQuestionIds = collect($questions)->pluck('id')->all();
            $selectedQuestionIds = array_values(array_intersect($selectedQuestionIds, $validQuestionIds));
        }

        $promptOptions = [
            'total' => max(1, min(50, $request->integer('total') ?: 6)),
            'easy' => max(0, $request->integer('easy') ?: 2),
            'medium' => max(0, $request->integer('medium') ?: 2),
            'hard' => max(0, $request->integer('hard') ?: 2),
            'focus' => $request->string('focus')->toString(),
        ];

        $cursorPrompt = session('written_sheet_chapter_prompt');

        if ($cursorPrompt === null && $sheetKind === 'practice' && $topicScope === 'one' && $topicId) {
            $topic = SyllabusTopic::query()->find($topicId);
            if ($topic) {
                $cursorPrompt = $this->fillBlankImportService->cursorPrompt($topic, $promptOptions);
            }
        } elseif ($cursorPrompt === null && $sheetKind === 'test' && $chapter) {
            $firstTopic = $chapter->topics->first();
            if ($firstTopic) {
                $cursorPrompt = $this->fillBlankImportService->cursorPromptForChapter($chapter, [[
                    'topic_id' => $firstTopic->id,
                    'topic_name' => 'Mixed chapter test',
                    'easy' => $promptOptions['easy'],
                    'medium' => $promptOptions['medium'],
                    'hard' => $promptOptions['hard'],
                ]]);
            }
        }

        return Inertia::render('Admin/WrittenSheets/Create', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'chapters' => $chapters,
            'topics' => $topics,
            'questions' => $questions,
            'supportsDiagrams' => $chapter ? DiagramQuestionSupport::looksLikeGeometryChapter($chapter) : false,
            'filters' => [
                'chapter_id' => $chapterId,
                'topic_id' => $topicId,
                'topic_scope' => $topicScope,
                'topic_ids' => $topicIds,
                'sheet_kind' => $sheetKind,
                'source_mode' => $request->string('source_mode')->toString() ?: 'bank',
                'question_ids' => $selectedQuestionIds,
            ],
            'selectedQuestionIds' => $selectedQuestionIds,
            'cursorPrompt' => $cursorPrompt,
            'promptOptions' => $promptOptions,
            'chapterPlan' => session('written_sheet_chapter_plan', []),
            'manualQuestionsDraft' => session('manual_questions_draft', []),
            'answerKeyDraft' => session('answer_key_draft', []),
        ]);
    }

    public function chapterPrompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'sheet_kind' => ['nullable', 'in:practice,test'],
            'source_mode' => ['nullable', 'in:bank,manual,pdf'],
            'topic_scope' => ['nullable', 'in:one,multiple'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'exists:syllabus_topics,id'],
            'plan' => ['required', 'array', 'min:1'],
            'plan.*.topic_id' => ['required', 'exists:syllabus_topics,id'],
            'plan.*.topic_name' => ['nullable', 'string'],
            'plan.*.easy' => ['nullable', 'integer', 'min:0'],
            'plan.*.medium' => ['nullable', 'integer', 'min:0'],
            'plan.*.hard' => ['nullable', 'integer', 'min:0'],
        ]);

        $chapter = SyllabusChapter::query()->findOrFail($validated['chapter_id']);

        try {
            $prompt = $this->fillBlankImportService->cursorPromptForWrittenChapter($chapter, $validated['plan']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $query = [
            'chapter_id' => $chapter->id,
            'sheet_kind' => $validated['sheet_kind'] ?? 'practice',
            'source_mode' => $validated['source_mode'] ?? 'manual',
            'topic_scope' => $validated['topic_scope'] ?? 'multiple',
        ];

        if (! empty($validated['topic_ids'])) {
            $query['topic_ids'] = $validated['topic_ids'];
        }

        return redirect()
            ->route('admin.written-sheets.create', $query)
            ->with('written_sheet_chapter_prompt', $prompt)
            ->with('written_sheet_chapter_plan', $validated['plan']);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->sanitizeWrittenSheetInput($request);

        $sourceMode = $request->input('source_mode');

        $validator = Validator::make($request->all(), [
            'source_mode' => ['required', 'in:bank,manual,pdf'],
            'sheet_kind' => ['required', 'in:practice,test'],
            'chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'topic_scope' => ['nullable', 'in:one,multiple'],
            'topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'exists:syllabus_topics,id'],
            'pdf_import_token' => ['exclude_unless:source_mode,pdf', 'required', 'uuid'],
            'answer_key' => ['exclude_unless:source_mode,pdf', 'required', 'array', 'min:1'],
            'answer_key.*.correct_answer' => ['required', 'string', 'max:'.WrittenSheetAnswerKeyParser::MAX_ANSWER_LENGTH],
            'answer_key.*.answer_format' => ['nullable', 'in:integer,decimal,fraction,text'],
            'answer_key.*.method_hint' => ['nullable', 'string'],
            'answer_key.*.topic_name' => ['nullable', 'string'],
            'answer_key.*.syllabus_topic_id' => ['nullable', 'integer'],
            'question_ids' => [
                Rule::excludeIf(fn () => in_array($sourceMode, ['manual', 'pdf'], true)),
                'required_if:source_mode,bank',
                'array',
                'min:1',
            ],
            'question_ids.*' => ['integer', 'exists:questions,id'],
            'manual_questions' => [
                Rule::excludeIf(fn () => in_array($sourceMode, ['bank', 'pdf'], true)),
                'required_if:source_mode,manual',
                'array',
                'min:1',
            ],
            'manual_questions.*.question_text' => ['required', 'string'],
            'manual_questions.*.correct_answer' => ['required', 'string'],
            'manual_questions.*.answer_format' => ['nullable', 'in:integer,decimal,fraction,text'],
            'manual_questions.*.method_hint' => ['nullable', 'string'],
            'manual_questions.*.explanation' => ['nullable', 'string'],
            'manual_questions.*.topic_name' => ['nullable', 'string'],
            'manual_questions.*.syllabus_topic_id' => ['nullable', 'integer'],
            'chapter_plan' => ['nullable', 'array'],
            'chapter_plan.*.topic_id' => ['nullable', 'integer', 'exists:syllabus_topics,id'],
            'chapter_plan.*.easy' => ['nullable', 'integer', 'min:0'],
            'chapter_plan.*.medium' => ['nullable', 'integer', 'min:0'],
            'chapter_plan.*.hard' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            $request->session()->flash('manual_questions_draft', $request->input('manual_questions', []));
            $request->session()->flash('answer_key_draft', $request->input('answer_key', []));
            $request->session()->flash('written_sheet_chapter_plan', $request->input('chapter_plan', []));

            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        if (! empty($validated['chapter_plan'])) {
            $request->session()->put('written_sheet_chapter_plan', $validated['chapter_plan']);
        }

        try {
            $chapter = SyllabusChapter::query()->findOrFail($validated['chapter_id']);
            $topicScope = ($validated['topic_scope'] ?? 'one') === 'multiple' ? 'multiple' : 'one';
            $topicIds = collect($validated['topic_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();
            $topic = isset($validated['topic_id'])
                ? SyllabusTopic::query()->find($validated['topic_id'])
                : null;
            $useChapterScope = $validated['sheet_kind'] === 'test'
                || ($validated['sheet_kind'] === 'practice' && $topicScope === 'multiple');

            if ($validated['source_mode'] === 'pdf' && ! $useChapterScope && ! $topic) {
                return back()
                    ->withInput()
                    ->with('error', 'Select a topic for a written practice sheet.')
                    ->with('answer_key_draft', $validated['answer_key'] ?? []);
            }

            if ($validated['source_mode'] === 'pdf') {
                $pdfImport = $this->pdfImportService->pull($validated['pdf_import_token']);

                if ($pdfImport === null) {
                    return back()
                        ->withInput()
                        ->with('error', 'PDF upload expired. Upload your worksheet PDF again.')
                        ->with('answer_key_draft', $validated['answer_key'] ?? []);
                }

                $worksheet = $this->writtenSheetService->createFromAnswerKey(
                    $chapter,
                    $useChapterScope ? null : $topic,
                    $validated['sheet_kind'],
                    $validated['answer_key'],
                    $request->user(),
                    $validated['notes'] ?? null,
                );

                $this->writtenSheetService->attachUploadedPdf(
                    $worksheet,
                    $pdfImport['pdf_path'],
                    $validated['pdf_import_token'],
                );
            } elseif ($validated['source_mode'] === 'manual') {
                $worksheet = $this->writtenSheetService->createFromManualQuestions(
                    $chapter,
                    $useChapterScope ? null : $topic,
                    $validated['sheet_kind'],
                    $validated['manual_questions'],
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            } elseif ($useChapterScope) {
                $worksheet = $this->writtenSheetService->createChapterTest(
                    $chapter,
                    $validated['question_ids'],
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            } else {
                if (! $topic) {
                    return back()
                        ->withInput()
                        ->with('error', 'Select a topic for a written practice sheet.')
                        ->with('manual_questions_draft', $validated['manual_questions'] ?? [])
                        ->with('answer_key_draft', $validated['answer_key'] ?? [])
                        ->with('written_sheet_chapter_plan', $validated['chapter_plan'] ?? session('written_sheet_chapter_plan', []));
                }

                $worksheet = $this->writtenSheetService->createFromTopic(
                    $topic,
                    $validated['question_ids'],
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            }

            if ($validated['source_mode'] !== 'pdf') {
                $this->writtenSheetService->generatePdf($worksheet);
            }

            $request->session()->forget(['manual_questions_draft', 'answer_key_draft']);
        } catch (\InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage())
                ->with('manual_questions_draft', $validated['manual_questions'] ?? [])
                ->with('answer_key_draft', $validated['answer_key'] ?? [])
                ->with('written_sheet_chapter_plan', $validated['chapter_plan'] ?? session('written_sheet_chapter_plan', []));
        } catch (\Throwable $e) {
            report($e);

            $errorMessage = $validated['source_mode'] === 'pdf'
                ? 'Could not save the uploaded PDF worksheet. '
                : 'Could not generate the PDF. ';

            return back()
                ->withInput()
                ->with('error', $errorMessage.$e->getMessage())
                ->with('manual_questions_draft', $validated['manual_questions'] ?? [])
                ->with('answer_key_draft', $validated['answer_key'] ?? [])
                ->with('written_sheet_chapter_plan', $validated['chapter_plan'] ?? session('written_sheet_chapter_plan', []));
        }

        $successMessage = $validated['source_mode'] === 'pdf'
            ? 'Uploaded worksheet PDF saved. Review it below, then verify to allow assigning.'
            : 'Written sheet created. Review the PDF, then verify to allow assigning.';

        return redirect()
            ->route('admin.written-sheets.show', $worksheet)
            ->with('success', $successMessage);
    }

    public function stagePdf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pdf' => ['required', 'file', 'max:20480'],
        ], [
            'pdf.required' => 'Choose a PDF file first.',
            'pdf.max' => 'PDF must be smaller than 20 MB.',
        ]);

        if (! $this->isPdfUpload($request->file('pdf'))) {
            return response()->json([
                'error' => 'Please upload a valid PDF file (.pdf).',
            ], 422);
        }

        try {
            $result = $this->pdfImportService->stage($request->file('pdf'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    public function parseAnswerPdf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pdf' => ['required', 'file', 'max:10240'],
            'worksheet_pdf_token' => ['nullable', 'uuid'],
        ], [
            'pdf.required' => 'Choose an answer sheet PDF first.',
            'pdf.max' => 'Answer sheet PDF must be smaller than 10 MB.',
        ]);

        if (! $this->isPdfUpload($request->file('pdf'))) {
            return response()->json([
                'error' => 'Please upload a valid PDF file (.pdf).',
            ], 422);
        }

        if (! empty($validated['worksheet_pdf_token'])
            && $this->pdfImportService->peek($validated['worksheet_pdf_token']) === null) {
            return response()->json([
                'error' => 'Worksheet PDF upload expired. Upload the worksheet PDF again, then upload the answer sheet.',
            ], 422);
        }

        try {
            $result = $this->pdfImportService->parseAnswerSheet(
                $request->file('pdf'),
                $validated['worksheet_pdf_token'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    public function importZipPack(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pack' => ['required', 'file', 'mimes:zip', 'max:51200'],
            'chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'sheet_kind' => ['nullable', 'in:practice,test'],
            'topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'topic_scope' => ['nullable', 'in:one,multiple'],
            'notes' => ['nullable', 'string'],
        ], [
            'pack.required' => 'Choose a .zip file containing questions.json and diagram images.',
            'pack.mimes' => 'Upload a .zip file (questions.json + JPG/PNG images).',
        ]);

        $chapter = SyllabusChapter::query()->with('topics')->findOrFail($validated['chapter_id']);
        $sheetKind = $validated['sheet_kind'] ?? 'test';
        $topicScope = ($validated['topic_scope'] ?? 'one') === 'multiple' ? 'multiple' : 'one';
        $topic = ! empty($validated['topic_id'])
            ? $chapter->topics->firstWhere('id', (int) $validated['topic_id'])
            : null;

        if ($sheetKind === 'practice' && $topicScope === 'one' && ! $topic) {
            return back()->with('error', 'Select a topic for a written practice sheet zip import.');
        }

        try {
            $scopeTopic = ($sheetKind === 'practice' && $topicScope === 'one') ? $topic : null;

            $result = $this->zipImportService->importPack(
                $request->file('pack'),
                $request->user(),
                $scopeTopic,
                $scopeTopic ? null : $chapter,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $questionIds = collect($result['saved'])->pluck('id')->all();

        if ($questionIds === []) {
            return back()->with('error', 'No questions could be imported from the zip file.');
        }

        try {
            if ($sheetKind === 'test' || $topicScope === 'multiple' || ! $topic) {
                $worksheet = $this->writtenSheetService->createChapterTest(
                    $chapter,
                    $questionIds,
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            } else {
                $worksheet = $this->writtenSheetService->createFromTopic(
                    $topic,
                    $questionIds,
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            }

            $this->writtenSheetService->generatePdf($worksheet);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not generate the written sheet PDF. '.$e->getMessage());
        }

        $message = count($questionIds).' question(s) imported and written sheet PDF generated.';
        if ($result['diagram_count'] > 0) {
            $message .= " {$result['diagram_count']} diagram(s) attached.";
        }
        if (($result['missing_diagram_count'] ?? 0) > 0) {
            $message .= " Warning: {$result['missing_diagram_count']} geometry sum(s) marked needs_diagram but had no image in the zip.";
        }

        return redirect()
            ->route('admin.written-sheets.show', $worksheet)
            ->with($result['missing_diagram_count'] > 0 ? 'warning' : 'success', $message);
    }

    public function show(Request $request, Worksheet $worksheet): Response
    {
        abort_unless($worksheet->isWritten(), 404);

        $activeYear = AcademicYear::active();
        $selectedStudentId = $request->integer('student_id') ?: null;
        $students = $this->assignmentService->activeStudentsForAssignment($activeYear?->id);
        $studentProgress = null;

        if ($selectedStudentId && $activeYear) {
            $enrollment = Student::find($selectedStudentId)?->enrollmentForYear($activeYear->id);

            if ($enrollment) {
                $studentProgress = $this->assignmentService->studentProgressForWorksheet(
                    $enrollment->id,
                    $worksheet->id,
                );
            }
        }

        $assignments = $worksheet->isWrittenVerified()
            ? $this->assignmentService->assignmentsForWorksheet($worksheet->id)->all()
            : [];

        return Inertia::render('Admin/WrittenSheets/Show', [
            'sheet' => $this->writtenSheetService->detail($worksheet),
            'students' => $students,
            'selectedStudentId' => $selectedStudentId,
            'studentProgress' => $studentProgress,
            'assignments' => $assignments,
            'activeYear' => $activeYear?->only(['id', 'name']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function regenerate(Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        if ($this->writtenSheetService->usesUploadedPdf($worksheet)) {
            return back()->with('error', 'This sheet uses your uploaded PDF. Create a new sheet to change the PDF file.');
        }

        try {
            $this->writtenSheetService->generatePdf($worksheet);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'PDF regenerated. Please review again before verifying.');
    }

    public function verify(Request $request, Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        try {
            $this->writtenSheetService->verify($worksheet, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Written sheet verified. You can now assign it to students.');
    }

    public function reject(Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        $this->writtenSheetService->reject($worksheet);

        return back()->with('success', 'Written sheet sent back to draft.');
    }

    public function replacePdf(Request $request, Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        $validated = $request->validate([
            'pdf_import_token' => ['required', 'uuid'],
        ]);

        $pdfImport = $this->pdfImportService->pull($validated['pdf_import_token']);

        if ($pdfImport === null) {
            return back()->with('error', 'PDF upload expired. Upload the replacement PDF again.');
        }

        try {
            $this->writtenSheetService->replacePdf(
                $worksheet,
                $pdfImport['pdf_path'],
                $validated['pdf_import_token'],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $worksheet->isWrittenVerified()
            ? 'Worksheet PDF replaced. Existing assignments stay active — students who have not uploaded yet will get the new PDF.'
            : 'Worksheet PDF replaced. Review it below, then verify if needed.';

        return back()->with('success', $message);
    }

    public function removePdf(Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        try {
            $this->writtenSheetService->removePdf($worksheet);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            'Sheet cleared — PDF and all questions removed. Re-import a zip or upload a new PDF to start again.',
        );
    }

    public function reimportZipPack(Request $request, Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        $validated = $request->validate([
            'pack' => ['required', 'file', 'mimes:zip', 'max:51200'],
        ], [
            'pack.required' => 'Choose a .zip file containing questions.json and diagram images.',
            'pack.mimes' => 'Upload a .zip file (questions.json + JPG/PNG images).',
        ]);

        try {
            $result = $this->writtenSheetService->reimportZipPack(
                $worksheet,
                $request->file('pack'),
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not re-import the zip pack. '.$e->getMessage());
        }

        $questionCount = count($result['saved']);
        $message = "{$questionCount} question(s) re-imported and written sheet PDF regenerated.";

        if ($result['diagram_count'] > 0) {
            $message .= " {$result['diagram_count']} diagram(s) attached.";
        }

        if (($result['missing_diagram_count'] ?? 0) > 0) {
            $message .= " Warning: {$result['missing_diagram_count']} geometry sum(s) marked needs_diagram but had no image in the zip.";
        }

        return back()->with(
            ($result['missing_diagram_count'] ?? 0) > 0 ? 'warning' : 'success',
            $message,
        );
    }

    public function download(Worksheet $worksheet): StreamedResponse
    {
        abort_unless($worksheet->isWritten() && $worksheet->written_pdf_path, 404);

        return Storage::disk('public')->download(
            $worksheet->written_pdf_path,
            ($worksheet->set_code ?: 'written-sheet').'.pdf',
        );
    }

    public function manualGrade(Request $request, SetAssignment $assignment): RedirectResponse
    {
        $assignment->loadMissing('practiceSet');
        abort_unless($assignment->practiceSet?->isWritten(), 404);

        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:0'],
            'max_score' => ['required', 'integer', 'min:1'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        if ((int) $validated['score'] > (int) $validated['max_score']) {
            throw ValidationException::withMessages([
                'score' => 'Marks obtained cannot be more than total marks.',
            ]);
        }

        try {
            $this->submissionService->applyManualGrade($assignment, [
                'score' => (int) $validated['score'],
                'max_score' => (int) $validated['max_score'],
                'feedback' => $validated['feedback'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Marks and feedback saved. They will appear in the weekly parent report.');
    }

    private function sanitizeWrittenSheetInput(Request $request): void
    {
        $sourceMode = match ($request->input('source_mode')) {
            'manual' => 'manual',
            'pdf' => 'pdf',
            default => 'bank',
        };

        $validFormats = ['integer', 'decimal', 'fraction', 'text'];
        $chapterId = (int) $request->input('chapter_id');
        $validTopicIds = $chapterId > 0
            ? SyllabusChapter::query()->with('topics:id,syllabus_chapter_id')->find($chapterId)?->topics->pluck('id')->all() ?? []
            : [];

        $sanitizeAnswerRow = function (array $row) use ($validFormats, $validTopicIds): array {
            $format = strtolower(trim((string) ($row['answer_format'] ?? 'text')));
            if (! in_array($format, $validFormats, true)) {
                $format = 'text';
            }

            $topicId = $row['syllabus_topic_id'] ?? null;
            if ($topicId === '' || $topicId === 0 || $topicId === '0') {
                $topicId = null;
            } elseif ($topicId !== null && ! in_array((int) $topicId, $validTopicIds, true)) {
                $topicId = null;
            }

            return [
                'correct_answer' => $this->normalizeStoredAnswer(trim((string) ($row['correct_answer'] ?? ''))),
                'answer_format' => $format,
                'method_hint' => array_key_exists('method_hint', $row) && $row['method_hint'] !== null
                    ? trim((string) $row['method_hint'])
                    : null,
                'topic_name' => trim((string) ($row['topic_name'] ?? $row['topic'] ?? '')),
                'syllabus_topic_id' => $topicId,
            ];
        };

        $manualQuestions = collect($request->input('manual_questions', []))
            ->filter(fn ($row) => is_array($row))
            ->map(function (array $row) use ($validFormats, $validTopicIds) {
                $format = strtolower(trim((string) ($row['answer_format'] ?? 'text')));
                if (! in_array($format, $validFormats, true)) {
                    $format = 'text';
                }

                $topicId = $row['syllabus_topic_id'] ?? null;
                if ($topicId === '' || $topicId === 0 || $topicId === '0') {
                    $topicId = null;
                } elseif ($topicId !== null && ! in_array((int) $topicId, $validTopicIds, true)) {
                    $topicId = null;
                }

                return [
                    'question_text' => trim((string) ($row['question_text'] ?? '')),
                    'correct_answer' => trim((string) ($row['correct_answer'] ?? '')),
                    'answer_format' => $format,
                    'method_hint' => array_key_exists('method_hint', $row) && $row['method_hint'] !== null
                        ? trim((string) $row['method_hint'])
                        : null,
                    'explanation' => array_key_exists('explanation', $row) && $row['explanation'] !== null
                        ? trim((string) $row['explanation'])
                        : null,
                    'topic_name' => trim((string) ($row['topic_name'] ?? $row['topic'] ?? '')),
                    'syllabus_topic_id' => $topicId,
                    'difficulty' => trim((string) ($row['difficulty'] ?? '')),
                ];
            })
            ->filter(fn (array $row) => $row['question_text'] !== '' && $row['correct_answer'] !== '')
            ->values()
            ->all();

        $answerKey = collect($request->input('answer_key', []))
            ->filter(fn ($row) => is_array($row))
            ->map($sanitizeAnswerRow)
            ->filter(fn (array $row) => $row['correct_answer'] !== '')
            ->values()
            ->all();

        $payload = [
            'chapter_id' => $chapterId > 0 ? $chapterId : $request->input('chapter_id'),
            'topic_id' => $request->input('topic_id') ?: null,
            'source_mode' => $sourceMode,
        ];

        if ($sourceMode === 'manual') {
            $payload['manual_questions'] = $manualQuestions;
            $payload['question_ids'] = null;
            $payload['answer_key'] = null;
        } elseif ($sourceMode === 'pdf') {
            $payload['answer_key'] = $answerKey;
            $payload['manual_questions'] = null;
            $payload['question_ids'] = null;
        } else {
            $payload['manual_questions'] = null;
            $payload['answer_key'] = null;
        }

        $request->merge($payload);
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

    private function normalizeStoredAnswer(string $answer): string
    {
        if (preg_match('/\bcorrect\s*answer\s*:?\s*(.+?)(?:\s+explanation\s*:|$)/iu', $answer, $match)) {
            $answer = trim($match[1]);
        }

        if (mb_strlen($answer) > WrittenSheetAnswerKeyParser::MAX_ANSWER_LENGTH) {
            $answer = trim(mb_substr($answer, 0, WrittenSheetAnswerKeyParser::MAX_ANSWER_LENGTH));
        }

        return $answer;
    }
}
