<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\SyllabusChapter;
use App\Services\AdminGradeContext;
use App\Services\ClassCoverageService;
use App\Services\ExamPlanService;
use App\Services\StudentMentorService;
use App\Services\StudyPlanReminderEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SchoolStudyPlanController extends Controller
{
    public function __construct(
        private ClassCoverageService $coverageService,
        private AdminGradeContext $gradeContext,
        private StudyPlanReminderEmailService $reminderEmails,
        private ExamPlanService $examPlanService,
        private StudentMentorService $mentorService,
    ) {}

    public function index(Request $request): Response
    {
        $activeYear = AcademicYear::active();
        $gradeLevel = $this->gradeContext->resolve($request);
        $studentId = $request->integer('student_id') ?: null;
        $user = $request->user();

        if ($studentId && $user?->isMentor() && ! $user->isAdmin()) {
            $this->mentorService->assertCanAccessStudent($user, $studentId);
        }

        $breakdown = $gradeLevel
            ? $this->reminderEmails->classBreakdown($gradeLevel, $activeYear)
            : [
                'students' => [],
                'with_plan' => [],
                'without_plan' => [],
                'summary' => [
                    'total' => 0,
                    'with_plan' => 0,
                    'without_plan' => 0,
                    'without_plan_with_email' => 0,
                    'without_plan_no_email' => 0,
                ],
            ];

        $students = collect($breakdown['students']);

        if ($user?->isMentor() && ! $user->isAdmin()) {
            $allowed = $this->mentorService->studentIdsForUser($user);
            $students = $students->filter(fn (array $row) => in_array((int) ($row['id'] ?? 0), $allowed, true))->values();
            $breakdown['students'] = $students->all();
        }

        $selectedStudent = null;
        $classCoverage = ['chapters' => [], 'under_study_chapter_id' => null];
        $context = null;
        $examPlans = [];
        $upcomingExams = [];
        $syllabusChapters = [];

        if ($studentId) {
            $enrollment = StudentEnrollment::query()
                ->with(['student:id,name', 'gradeLevel:id,name', 'board:id,name'])
                ->where('student_id', $studentId)
                ->when($activeYear, fn ($q) => $q->where('academic_year_id', $activeYear->id))
                ->when(
                    $gradeLevel && ! ($user?->isMentor() && ! $user->isAdmin()),
                    fn ($q) => $q->where('grade_level_id', $gradeLevel->id),
                )
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

                $examPlans = $this->examPlanService->plansForEnrollment($enrollment, true)->values()->all();
                $split = $this->examPlanService->splitPlansByTiming(collect($examPlans));
                $upcomingExams = $split['upcoming']->values()->all();
                $syllabusChapters = $this->examPlanService->chapterOptionsForEnrollment($enrollment)->values()->all();
            }
        }

        return Inertia::render('Admin/SchoolStudyPlan/Index', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'students' => $students,
            'withPlanStudents' => $breakdown['with_plan'],
            'withoutPlanStudents' => $breakdown['without_plan'],
            'summary' => $breakdown['summary'],
            'filters' => [
                'student_id' => $selectedStudent['id'] ?? null,
            ],
            'selectedStudent' => $selectedStudent,
            'classCoverage' => $classCoverage,
            'context' => $context,
            'examPlans' => $examPlans,
            'upcomingExams' => $upcomingExams,
            'syllabusChapters' => $syllabusChapters,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
        ]);
    }

    public function sendReminders(Request $request): RedirectResponse
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        abort_unless($gradeLevel, 422, 'Select a class from the top bar first.');

        $counts = $this->reminderEmails->sendToMissingInGrade($gradeLevel);

        if ($counts['sent'] === 0 && $counts['failed'] === 0) {
            if ($counts['already_planned'] > 0 && ($counts['skipped'] === 0)) {
                return back()->with('warning', 'Everyone in this class already has a school study plan marked.');
            }

            return back()->with('warning', 'No emails sent — students without a plan may be missing email addresses.');
        }

        $parts = [];

        if ($counts['sent'] > 0) {
            $parts[] = "{$counts['sent']} sent";
        }

        if ($counts['skipped'] > 0) {
            $parts[] = "{$counts['skipped']} skipped (no email)";
        }

        if ($counts['failed'] > 0) {
            $parts[] = "{$counts['failed']} failed";
        }

        $tone = $counts['failed'] > 0 ? 'warning' : 'success';

        return back()->with($tone, 'Study plan reminders: '.implode(', ', $parts).'.');
    }

    public function update(Request $request, Student $student, SyllabusChapter $syllabusChapter): RedirectResponse
    {
        $user = $request->user();
        if ($user?->isMentor() && ! $user->isAdmin()) {
            $this->mentorService->assertCanAccessStudent($user, $student->id);
        }

        $activeYear = AcademicYear::active();
        $gradeLevel = $this->gradeContext->resolve($request);

        $enrollment = StudentEnrollment::query()
            ->where('student_id', $student->id)
            ->when($activeYear, fn ($q) => $q->where('academic_year_id', $activeYear->id))
            ->when(
                $gradeLevel && ! ($user?->isMentor() && ! $user->isAdmin()),
                fn ($q) => $q->where('grade_level_id', $gradeLevel->id),
            )
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

        $message = match ($validated['status']) {
            'under_study', 'studied' => 'Study plan updated. Chapter sets are due today.',
            default => 'School study plan updated.',
        };

        return redirect()
            ->route('admin.school-study-plan.index', ['student_id' => $student->id])
            ->with('success', $message);
    }
}
