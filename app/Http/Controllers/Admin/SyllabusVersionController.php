<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ChapterHead;
use App\Models\Subject;
use App\Models\SyllabusVersion;
use App\Services\AdminGradeContext;
use App\Services\SyllabusCarryForwardService;
use App\Services\SyllabusImportService;
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
            'gradeLevels' => $this->gradeContext->classLevels(),
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

    public function show(SyllabusVersion $syllabusVersion): Response
    {
        $syllabusVersion->load([
            'board',
            'gradeLevel',
            'subject',
            'academicYear',
        ]);

        return Inertia::render('Admin/Syllabus/Show', [
            'version' => $syllabusVersion,
            'rows' => $this->importService->flattenToRows($syllabusVersion),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name']),
            'chapterHeads' => ChapterHead::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
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

    public function updateRows(Request $request, SyllabusVersion $syllabusVersion): RedirectResponse
    {
        $validated = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.chapter_id' => ['nullable', 'integer'],
            'rows.*.chapter_number' => ['nullable', 'string', 'max:20'],
            'rows.*.chapter_name' => ['nullable', 'string', 'max:255'],
            'rows.*.chapter_head_id' => ['nullable', 'integer', 'exists:chapter_heads,id'],
            'rows.*.topic_name' => ['nullable', 'string', 'max:255'],
            'rows.*.learning_outcomes' => ['nullable', 'string'],
            'rows.*.difficulty' => ['nullable', 'string', 'max:20'],
            'rows.*.planned_periods' => ['nullable'],
            'rows.*.remarks' => ['nullable', 'string'],
        ]);

        $this->importService->syncRows($syllabusVersion, $validated['rows']);

        return redirect()
            ->route('admin.syllabus.show', $syllabusVersion)
            ->with('success', 'Syllabus saved.');
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'board_id' => ['required', 'exists:boards,id'],
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'file' => ['required', 'file', 'extensions:xlsx,xls', 'max:10240'],
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
            'file' => ['required', 'file', 'extensions:xlsx,xls', 'max:10240'],
        ]);

        return $this->processImport($request, $syllabusVersion);
    }

    private function processImport(Request $request, SyllabusVersion $version): RedirectResponse
    {
        try {
            $count = $this->importService->import($request->file('file'), $version);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Could not read the Excel file. Use .xlsx with columns: Chapter No., Main Topic, Sub-Topic.');
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
