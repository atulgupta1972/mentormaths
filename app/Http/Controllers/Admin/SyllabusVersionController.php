<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ChapterHead;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Services\AdminGradeContext;
use App\Services\SyllabusCarryForwardService;
use App\Services\SyllabusImportService;
use App\Services\TextbookChapterBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SyllabusVersionController extends Controller
{
    public function __construct(
        private SyllabusImportService $importService,
        private SyllabusCarryForwardService $carryForwardService,
        private AdminGradeContext $gradeContext,
        private TextbookChapterBookService $bookService,
    ) {}

    public function index(Request $request): Response
    {
        $grade = $this->gradeContext->resolve($request);

        $versionsQuery = SyllabusVersion::query()
            ->with(['board:id,code,name', 'gradeLevel:id,name', 'subject:id,name', 'academicYear:id,name'])
            ->withCount(['chapters'])
            ->when($grade, fn ($q) => $q->where('grade_level_id', $grade->id))
            ->latest();

        $activeYear = AcademicYear::active();

        return Inertia::render('Admin/Syllabus/Index', [
            'versions' => $versionsQuery->get()->map(fn ($version) => [
                ...$version->toArray(),
                'label' => $version->label(),
            ]),
            'boards' => Board::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'gradeLevels' => $this->gradeContext->classLevelOptions(),
            'subjects' => Subject::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'importDefaults' => [
                'board_id' => Board::query()->where('code', 'CBSE')->value('id'),
                'grade_level_id' => $grade?->id,
                'subject_id' => Subject::query()->where('code', 'MATHS')->value('id'),
                'academic_year_id' => $activeYear?->id,
            ],
        ]);
    }

    public function show(int $syllabusVersion): Response|RedirectResponse
    {
        $version = SyllabusVersion::query()->find($syllabusVersion);

        if (! $version) {
            return redirect()
                ->route('admin.syllabus.index')
                ->with('error', "That syllabus (id {$syllabusVersion}) was not found. Open one from the list below.");
        }

        $version->load([
            'board',
            'gradeLevel',
            'subject',
            'academicYear',
        ]);

        return Inertia::render('Admin/Syllabus/Show', [
            'version' => $version,
            'rows' => $this->importService->flattenToRows($version),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'chapterHeads' => ChapterHead::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'contentMoveTargets' => $this->bookService->contentMoveTargets($version),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'board_id' => ['required', 'exists:boards,id'],
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
        ]);

        $version = SyllabusVersion::firstOrCreate(
            [
                'board_id' => $validated['board_id'],
                'grade_level_id' => $validated['grade_level_id'],
                'subject_id' => $validated['subject_id'],
                'academic_year_id' => $validated['academic_year_id'],
            ],
            ['status' => SyllabusVersion::STATUS_DRAFT],
        );

        return redirect()
            ->route('admin.syllabus.show', $version)
            ->with('success', $version->wasRecentlyCreated
                ? 'Syllabus created. Add chapters and topics below.'
                : 'Syllabus already exists — continue editing below.');
    }

    public function storeTopic(Request $request, SyllabusVersion $syllabusVersion): RedirectResponse
    {
        $validated = $request->validate([
            'chapter_id' => ['nullable', 'integer'],
            'chapter_number' => ['required_without:chapter_id', 'nullable', 'string', 'max:20'],
            'chapter_name' => ['required_without:chapter_id', 'nullable', 'string', 'max:255'],
            'chapter_head_id' => ['nullable', 'integer', 'exists:chapter_heads,id'],
            'topic_name' => ['required', 'string', 'max:255'],
            'learning_outcomes' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'planned_periods' => ['nullable'],
            'remarks' => ['nullable', 'string'],
        ]);

        if (! empty($validated['chapter_id'])) {
            abort_unless(
                $syllabusVersion->chapters()->whereKey($validated['chapter_id'])->exists(),
                422,
                'Chapter not found in this syllabus.',
            );
        }

        $this->importService->addTopic(
            $syllabusVersion,
            [
                'chapter_id' => $validated['chapter_id'] ?? null,
                'chapter_number' => $validated['chapter_number'] ?? null,
                'chapter_name' => $validated['chapter_name'] ?? null,
                'chapter_head_id' => $validated['chapter_head_id'] ?? null,
            ],
            [
                'topic_name' => $validated['topic_name'],
                'learning_outcomes' => $validated['learning_outcomes'] ?? null,
                'difficulty' => $validated['difficulty'] ?? null,
                'planned_periods' => $validated['planned_periods'] ?? null,
                'remarks' => $validated['remarks'] ?? null,
            ],
        );

        return redirect()
            ->route('admin.syllabus.show', $syllabusVersion)
            ->with('success', 'Topic added to syllabus.');
    }

    public function updateRows(Request $request, SyllabusVersion $syllabusVersion): RedirectResponse
    {
        $validated = $request->validate([
            'replace' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.chapter_id' => ['nullable', 'integer'],
            'rows.*.chapter_number' => ['nullable', 'string', 'max:20'],
            'rows.*.chapter_name' => ['nullable', 'string', 'max:255'],
            'rows.*.chapter_head_id' => ['nullable', 'integer', 'exists:chapter_heads,id'],
            'rows.*.ncert_verified' => ['nullable', 'boolean'],
            'rows.*.topic_name' => ['nullable', 'string', 'max:255'],
            'rows.*.learning_outcomes' => ['nullable', 'string'],
            'rows.*.difficulty' => ['nullable', 'string', 'max:20'],
            'rows.*.planned_periods' => ['nullable'],
            'rows.*.remarks' => ['nullable', 'string'],
        ]);

        $result = $this->importService->syncRows(
            $syllabusVersion,
            $validated['rows'],
            replaceExisting: (bool) ($validated['replace'] ?? false),
        );

        $success = ($validated['replace'] ?? false)
            ? (count($validated['rows']) > 0
                ? 'Syllabus replaced with the preview.'
                : 'All syllabus rows were deleted.')
            : 'Syllabus saved.';

        $kept = $result['kept_content_chapters'] ?? [];
        if ($kept !== []) {
            $success .= ' Kept uploaded MCQs for: '.implode(', ', $kept)
                .'. Those chapters stay so the book bank can be reused on another board or class.';
        }

        return redirect()
            ->route('admin.syllabus.show', $syllabusVersion)
            ->with('success', $success);
    }

    public function moveChapterContent(
        Request $request,
        SyllabusVersion $syllabusVersion,
        SyllabusChapter $syllabusChapter,
    ): RedirectResponse|JsonResponse {
        abort_unless((int) $syllabusChapter->syllabus_version_id === (int) $syllabusVersion->id, 404);

        $validated = $request->validate([
            'target_syllabus_chapter_id' => ['required', 'integer', 'exists:syllabus_chapters,id'],
        ]);

        $target = SyllabusChapter::query()->findOrFail($validated['target_syllabus_chapter_id']);

        try {
            $label = $this->bookService->moveSyllabusChapterContent($syllabusChapter, $target);
        } catch (\InvalidArgumentException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return back()->with('error', $exception->getMessage());
        }

        $message = 'Moved MCQs to '.$label.'. Remove this chapter from the table and save the syllabus.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()
            ->route('admin.syllabus.show', $syllabusVersion)
            ->with('success', $message);
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'board_id' => ['required', 'exists:boards,id'],
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'extensions:xlsx,xls', 'max:10240'],
        ]);

        $version = SyllabusVersion::firstOrCreate(
            [
                'board_id' => $validated['board_id'],
                'grade_level_id' => $validated['grade_level_id'],
                'subject_id' => $validated['subject_id'],
                'academic_year_id' => $validated['academic_year_id'],
            ],
            ['status' => SyllabusVersion::STATUS_DRAFT],
        );

        return $this->processImport($request, $version);
    }

    public function importIntoVersion(Request $request, SyllabusVersion $syllabusVersion): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'extensions:xlsx,xls', 'max:10240'],
        ]);

        return $this->processImport($request, $syllabusVersion);
    }

    public function previewImportIntoVersion(Request $request, SyllabusVersion $syllabusVersion): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'extensions:xlsx,xls', 'max:10240'],
        ]);

        try {
            $headerInfo = $this->importService->describeFileHeaders($request->file('file'));
            $rows = $this->importService->parseFileToPreviewRows($request->file('file'));
        } catch (\Throwable $e) {
            report($e);

            $message = str_contains(strtolower($e->getMessage()), 'zip')
                ? 'Server cannot read .xlsx files (PHP zip extension missing). Ask your host to enable ext-zip.'
                : 'Could not read the Excel file: '.$e->getMessage();

            return response()->json(['message' => $message], 422);
        }

        if ($headerInfo['missing'] !== []) {
            return response()->json([
                'message' => 'Missing required column(s): '.implode(', ', $headerInfo['missing'])
                    .'. Expected headers like Chapter No., Main Topic (Chapter), Sub-Topic, Key Concepts / Learning Outcomes.',
                'header_info' => $headerInfo,
            ], 422);
        }

        if ($rows->isEmpty()) {
            return response()->json([
                'message' => 'No topics found. Check that row 1 has headers: Chapter No., Main Topic (Chapter), Sub-Topic, etc.',
                'header_info' => $headerInfo,
            ], 422);
        }

        $warnings = [];

        if ($headerInfo['unrecognized'] !== []) {
            $warnings[] = 'Unrecognized column(s) will be ignored: '.implode(', ', $headerInfo['unrecognized']);
        }

        return response()->json([
            'rows' => $rows->values()->all(),
            'count' => $rows->count(),
            'filename' => $request->file('file')->getClientOriginalName(),
            'warnings' => $warnings,
            'header_info' => $headerInfo,
        ]);
    }

    public function clearRows(SyllabusVersion $syllabusVersion): RedirectResponse
    {
        $result = $this->importService->clearAllRows($syllabusVersion);
        $kept = $result['kept_content_chapters'] ?? [];

        $success = $kept === []
            ? 'All saved syllabus rows were deleted. Import from Excel or add rows manually.'
            : 'Empty syllabus rows were deleted. Kept uploaded MCQs for: '.implode(', ', $kept)
                .'. Those chapters stay so the book bank can be reused on another board or class.';

        return redirect()
            ->route('admin.syllabus.show', $syllabusVersion)
            ->with('success', $success);
    }

    private function processImport(Request $request, SyllabusVersion $version): RedirectResponse
    {
        try {
            $count = $this->importService->import($request->file('file'), $version);
        } catch (\Throwable $e) {
            report($e);

            $message = str_contains(strtolower($e->getMessage()), 'zip')
                ? 'Server cannot read .xlsx files (PHP zip extension missing). Ask your host to enable ext-zip.'
                : 'Could not read the Excel file: '.$e->getMessage();

            return back()->with('error', $message);
        }

        if ($count === 0) {
            return back()->with(
                'error',
                'No topics were imported. Check that row 1 has headers: Chapter No., Main Topic (Chapter), Sub-Topic, etc.',
            );
        }

        return redirect()
            ->route('admin.syllabus.show', $version)
            ->with('success', "Imported {$count} topic(s).");
    }

    public function carryForward(Request $request, SyllabusVersion $syllabusVersion): RedirectResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'exists:academic_years,id'],
        ]);

        $targetYear = AcademicYear::findOrFail($validated['academic_year_id']);

        try {
            $newVersion = $this->carryForwardService->carryForward($syllabusVersion, $targetYear);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.syllabus.show', $newVersion)
            ->with('success', "Syllabus carried forward to {$targetYear->name}.");
    }
}
