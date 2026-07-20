<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Student;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\FillBlankImportService;
use App\Services\SetAssignmentService;
use App\Services\WrittenSheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        ]);
    }

    public function chapterPrompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'sheet_kind' => ['nullable', 'in:practice,test'],
            'source_mode' => ['nullable', 'in:bank,manual'],
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
            $prompt = $this->fillBlankImportService->cursorPromptForChapter($chapter, $validated['plan']);
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

        $validator = Validator::make($request->all(), [
            'source_mode' => ['required', 'in:bank,manual'],
            'sheet_kind' => ['required', 'in:practice,test'],
            'chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'topic_scope' => ['nullable', 'in:one,multiple'],
            'topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'exists:syllabus_topics,id'],
            'question_ids' => ['exclude_if:source_mode,manual', 'required_if:source_mode,bank', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
            'manual_questions' => ['exclude_if:source_mode,bank', 'required_if:source_mode,manual', 'array', 'min:1'],
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

            if ($validated['source_mode'] === 'manual') {
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
                        ->with('written_sheet_chapter_plan', $validated['chapter_plan'] ?? session('written_sheet_chapter_plan', []));
                }

                $worksheet = $this->writtenSheetService->createFromTopic(
                    $topic,
                    $validated['question_ids'],
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            }

            $this->writtenSheetService->generatePdf($worksheet);

            $request->session()->forget(['manual_questions_draft']);
        } catch (\InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage())
                ->with('manual_questions_draft', $validated['manual_questions'] ?? [])
                ->with('written_sheet_chapter_plan', $validated['chapter_plan'] ?? session('written_sheet_chapter_plan', []));
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with('error', 'Could not generate the PDF. '.$e->getMessage())
                ->with('manual_questions_draft', $validated['manual_questions'] ?? [])
                ->with('written_sheet_chapter_plan', $validated['chapter_plan'] ?? session('written_sheet_chapter_plan', []));
        }

        return redirect()
            ->route('admin.written-sheets.show', $worksheet)
            ->with('success', 'Written sheet created. Review the PDF, then verify to allow assigning.');
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

    public function download(Worksheet $worksheet): StreamedResponse
    {
        abort_unless($worksheet->isWritten() && $worksheet->written_pdf_path, 404);

        return Storage::disk('public')->download(
            $worksheet->written_pdf_path,
            ($worksheet->set_code ?: 'written-sheet').'.pdf',
        );
    }

    private function sanitizeWrittenSheetInput(Request $request): void
    {
        $validFormats = ['integer', 'decimal', 'fraction', 'text'];
        $chapterId = (int) $request->input('chapter_id');
        $validTopicIds = $chapterId > 0
            ? SyllabusChapter::query()->with('topics:id,syllabus_chapter_id')->find($chapterId)?->topics->pluck('id')->all() ?? []
            : [];

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

        $sourceMode = $request->input('source_mode') === 'manual' ? 'manual' : 'bank';

        $payload = [
            'chapter_id' => $chapterId > 0 ? $chapterId : $request->input('chapter_id'),
            'topic_id' => $request->input('topic_id') ?: null,
            'source_mode' => $sourceMode,
        ];

        if ($sourceMode === 'manual') {
            $payload['manual_questions'] = $manualQuestions;
            $payload['question_ids'] = null;
        } else {
            $payload['manual_questions'] = null;
        }

        $request->merge($payload);
    }
}
