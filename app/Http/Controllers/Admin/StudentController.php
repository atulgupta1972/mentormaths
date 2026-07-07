<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Services\AdminGradeContext;
use App\Services\ExamPlanService;
use App\Services\QuestionResolutionService;
use App\Services\StudentAccountService;
use App\Services\StudentProgressSummaryService;
use App\Services\StudentProgressWhatsAppService;
use App\Services\StudentPromotionService;
use App\Support\AssignmentMailer;
use App\Support\StudentProgressMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentController extends Controller
{
    public function __construct(
        private StudentPromotionService $promotionService,
        private StudentAccountService $accountService,
        private AdminGradeContext $gradeContext,
        private ExamPlanService $examPlanService,
        private QuestionResolutionService $resolutionService,
        private StudentProgressSummaryService $progressSummaryService,
        private StudentProgressWhatsAppService $progressWhatsAppService,
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

        $examPlans = collect();
        $syllabusChapters = collect();

        if ($latest) {
            $examPlans = $this->examPlanService->plansForEnrollment($latest, true);
            $syllabusChapters = $this->examPlanService->chapterOptionsForEnrollment($latest)->values()->all();
        }

        $activeYear = AcademicYear::active();
        $currentYearEnrollment = $activeYear
            ? $student->enrollmentForYear($activeYear->id)
            : null;

        $resolutionEnrollment = $currentYearEnrollment ?? $latest;

        return Inertia::render('Admin/Students/Show', [
            'student' => $student,
            'accountActive' => $this->accountService->isActive($student),
            'currentYearEnrollment' => $currentYearEnrollment?->only(['id', 'status']),
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
            'examPlans' => $examPlans->values()->all(),
            'syllabusChapters' => $syllabusChapters,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
            'resolutionItems' => $resolutionEnrollment
                ? $this->resolutionService->pendingForEnrollment($resolutionEnrollment->id)
                : [],
            'helpRequestsCount' => $resolutionEnrollment
                ? $this->resolutionService->pendingCountForEnrollment($resolutionEnrollment->id)
                : 0,
            'defaultSummaryEmail' => AssignmentMailer::resolveStudentEmail($student),
        ]);
    }

    public function toggleActive(Student $student): RedirectResponse
    {
        try {
            if ($this->accountService->isActive($student)) {
                $this->accountService->deactivate($student);

                return back()->with('success', "{$student->name} deactivated. They cannot log in and are hidden from class lists.");
            }

            $this->accountService->activate($student);

            return back()->with('success', "{$student->name} activated. They can log in and appear in class lists again.");
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Student $student): RedirectResponse
    {
        $name = $student->name;

        $this->accountService->delete($student);

        return redirect()
            ->route('admin.students.index')
            ->with('success', "{$name} deleted. Their login, enrollments, and assignments have been removed.");
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

    public function sendProgressSummary(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['required', 'date'],
            'send_email' => ['sometimes', 'boolean'],
            'send_whatsapp' => ['sometimes', 'boolean'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return back()->with('error', 'Student has no active enrollment for the current year.');
        }

        $asOf = \Carbon\Carbon::parse($validated['as_of_date']);
        $summary = $this->progressSummaryService->build($enrollment, $asOf);

        $messages = [];
        $warnings = [];

        if ($request->boolean('send_email')) {
            $result = StudentProgressMailer::send(
                $student,
                $summary,
                $validated['email'] ?? null,
            );

            if ($result['sent']) {
                $messages[] = "Email sent to {$result['email']}.";
            } elseif ($result['error'] === 'no_email') {
                $warnings[] = 'No email on file — add one on the student profile or enter an address below.';
            } else {
                $warnings[] = 'Email could not be sent. Check mail settings.';
            }
        }

        if ($request->boolean('send_whatsapp')) {
            $notifications = $this->progressWhatsAppService->notificationsForSummary($student, $summary);

            if ($notifications === []) {
                $warnings[] = 'No WhatsApp recipients — tick Notify on at least one mobile number and save.';
            } else {
                session()->flash('whatsapp_notifications', $notifications);
                $messages[] = count($notifications).' WhatsApp message'.(count($notifications) === 1 ? '' : 's').' ready — use the green panel to copy or open.';
            }
        }

        if ($messages === [] && $warnings === []) {
            return back()->with('warning', 'Choose Email and/or WhatsApp to send the summary.');
        }

        $redirect = back();

        if ($messages !== []) {
            $redirect = $redirect->with('success', implode(' ', $messages));
        }

        if ($warnings !== []) {
            $redirect = $redirect->with('warning', implode(' ', $warnings));
        }

        return $redirect;
    }
}
