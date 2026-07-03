<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Services\AdminGradeContext;
use App\Services\StudentPromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function __construct(
        private StudentPromotionService $promotionService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $activeYear = AcademicYear::active();
        $grade = $this->gradeContext->resolve($request);

        $students = Student::query()
            ->with([
                'user:id,name,email',
                'enrollments' => fn ($query) => $query
                    ->when($activeYear, fn ($q) => $q->where('academic_year_id', $activeYear->id))
                    ->when($grade, fn ($q) => $q->where('grade_level_id', $grade->id))
                    ->with(['gradeLevel:id,name', 'board:id,code', 'academicYear:id,name']),
            ])
            ->when($activeYear && $grade, function ($q) use ($activeYear, $grade) {
                $q->whereHas('enrollments', fn ($eq) => $eq
                    ->where('academic_year_id', $activeYear->id)
                    ->where('grade_level_id', $grade->id));
            })
            ->orderBy('name')
            ->paginate(20);

        return Inertia::render('Admin/Students/Index', [
            'students' => $students,
            'activeYear' => $activeYear?->only(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
        ]);
    }

    public function show(Student $student): Response
    {
        $student->load('user:id,name,email');

        $history = $student->enrollmentHistory()->load(['academicYear', 'board', 'gradeLevel']);
        $latest = $this->promotionService->latestEnrollment($student);
        $nextGrade = $latest?->gradeLevel?->next();

        return Inertia::render('Admin/Students/Show', [
            'student' => $student,
            'enrollmentHistory' => $history,
            'latestEnrollment' => $latest,
            'nextGrade' => $nextGrade?->only(['id', 'name']),
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get(['id', 'name', 'is_active']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'boards' => Board::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'shareLinks' => [
                'login' => route('login'),
                'dashboard' => route('dashboard'),
            ],
        ]);
    }

    public function promote(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
            'school_name' => ['nullable', 'string', 'max:255'],
        ]);

        $toYear = AcademicYear::findOrFail($validated['academic_year_id']);
        $grade = GradeLevel::findOrFail($validated['grade_level_id']);
        $board = isset($validated['board_id']) ? Board::findOrFail($validated['board_id']) : null;

        try {
            $this->promotionService->promote(
                $student,
                $toYear,
                $grade,
                $board,
                $validated['school_name'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Student promoted to {$grade->name} for {$toYear->name}.");
    }

    public function bulkPromote(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_academic_year_id' => ['required', 'exists:academic_years,id'],
            'to_academic_year_id' => ['required', 'exists:academic_years,id', 'different:from_academic_year_id'],
        ]);

        $fromYear = AcademicYear::findOrFail($validated['from_academic_year_id']);
        $toYear = AcademicYear::findOrFail($validated['to_academic_year_id']);

        try {
            $result = $this->promotionService->bulkPromote($fromYear, $toYear);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Promoted {$result['promoted']} student(s) to {$toYear->name}.";

        if ($result['skipped'] > 0) {
            $message .= " Skipped {$result['skipped']}.";
        }

        return back()
            ->with('success', $message)
            ->with('promotion_errors', $result['errors']);
    }

    public function updateContacts(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'student_mobile' => ['nullable', 'string', 'max:15'],
            'parent1_mobile' => ['nullable', 'string', 'max:15'],
            'parent2_mobile' => ['nullable', 'string', 'max:15'],
            'notify_student_mobile' => ['sometimes', 'boolean'],
            'notify_parent1_mobile' => ['sometimes', 'boolean'],
            'notify_parent2_mobile' => ['sometimes', 'boolean'],
        ]);

        $student->update($validated);

        if ($student->user && array_key_exists('student_mobile', $validated)) {
            $student->user->update(['mobile' => $validated['student_mobile']]);
        }

        return back()->with('success', 'Contact and notification settings saved.');
    }
}
