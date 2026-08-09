<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\SyllabusChapter;
use App\Services\AdminGradeContext;
use App\Services\ClassCoverageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolStudyPlanController extends Controller
{
    public function __construct(
        private ClassCoverageService $coverageService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $activeYear = AcademicYear::active();
        $gradeLevel = $this->gradeContext->resolve($request);
        $studentId = $request->integer('student_id') ?: null;

        $students = collect();

        if ($activeYear && $gradeLevel) {
            $students = StudentEnrollment::query()
                ->with(['student:id,name', 'board:id,name'])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->whereHas('student')
                ->get()
                ->sortBy(fn (StudentEnrollment $enrollment) => $enrollment->student?->name)
                ->values()
                ->map(fn (StudentEnrollment $enrollment) => [
                    'id' => $enrollment->student_id,
                    'name' => $enrollment->student?->name,
                    'enrollment_id' => $enrollment->id,
                    'board_name' => $enrollment->board?->name,
                ]);
        }

        $selectedStudent = null;
        $classCoverage = ['chapters' => [], 'under_study_chapter_id' => null];
        $context = null;

        if ($studentId) {
            $enrollment = StudentEnrollment::query()
                ->with(['student:id,name', 'gradeLevel:id,name', 'board:id,name'])
                ->where('student_id', $studentId)
                ->when($activeYear, fn ($q) => $q->where('academic_year_id', $activeYear->id))
                ->when($gradeLevel, fn ($q) => $q->where('grade_level_id', $gradeLevel->id))
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->first();

            if ($enrollment) {
                $selectedStudent = [
                    'id' => $enrollment->student_id,
                    'name' => $enrollment->student?->name,
                ];
                $classCoverage = $this->coverageService->forEnrollment($enrollment);
                $context = [
                    'grade_name' => $enrollment->gradeLevel?->name,
                    'board_name' => $enrollment->board?->name,
                ];
            }
        }

        return Inertia::render('Admin/SchoolStudyPlan/Index', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'students' => $students,
            'filters' => [
                'student_id' => $selectedStudent['id'] ?? null,
            ],
            'selectedStudent' => $selectedStudent,
            'classCoverage' => $classCoverage,
            'context' => $context,
        ]);
    }

    public function update(Request $request, Student $student, SyllabusChapter $syllabusChapter): RedirectResponse
    {
        $activeYear = AcademicYear::active();
        $gradeLevel = $this->gradeContext->resolve($request);

        $enrollment = StudentEnrollment::query()
            ->where('student_id', $student->id)
            ->when($activeYear, fn ($q) => $q->where('academic_year_id', $activeYear->id))
            ->when($gradeLevel, fn ($q) => $q->where('grade_level_id', $gradeLevel->id))
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->first();

        abort_unless($enrollment, 404, 'No active enrollment for this student.');

        $validated = $request->validate([
            'status' => ['required', 'in:studied,under_study,none'],
        ]);

        match ($validated['status']) {
            'under_study' => $this->coverageService->markUnderStudy($enrollment, $syllabusChapter),
            'studied' => $this->coverageService->markStudied($enrollment, $syllabusChapter),
            'none' => $this->coverageService->clearCoverage($enrollment, $syllabusChapter),
        };

        return redirect()
            ->route('admin.school-study-plan.index', ['student_id' => $student->id])
            ->with('success', 'School study plan updated.');
    }
}
