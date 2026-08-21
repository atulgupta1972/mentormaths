<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\CoachingClass;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Services\AdminGradeContext;
use App\Services\ExamPlanService;
use App\Services\QuestionResolutionService;
use App\Services\StudentAccountService;
use App\Services\FormulaDrillReportService;
use App\Services\PendingWorkEmailService;
use App\Services\StudentMentorService;
use App\Services\StudentNotificationContactService;
use App\Services\StudentNotificationEmailService;
use App\Services\StudentProgressPdfService;
use App\Services\StudentProgressSummaryService;
use App\Services\StudentProgressWhatsAppService;
use App\Services\StudentPromotionService;
use App\Support\AssignmentMailer;
use App\Support\EnrollmentSource;
use App\Support\StudentProgressMailer;
use App\Support\StudentProgressWhatsAppMailer;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        private StudentNotificationContactService $notificationContactService,
        private StudentNotificationEmailService $notificationEmailService,
        private StudentProgressPdfService $progressPdfService,
        private PendingWorkEmailService $pendingWorkEmailService,
        private FormulaDrillReportService $formulaDrillReport,
        private StudentMentorService $mentorService,
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
            'mailSettings' => \App\Support\MailConfigStatus::forAdmin(),
            'gradeLevels' => GradeLevel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name']),
        ]);
    }

    public function show(Student $student): Response
    {
        $student->load([
            'user:id,name,email',
            'coachingClass:id,name,city',
            'coachingClassTeacher:id,coaching_class_id,name,mobile,is_active',
            'mentorUser:id,name,mobile',
        ]);

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

        $coachingClasses = CoachingClass::query()
            ->where('is_active', true)
            ->with(['teachers' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'city', 'is_active']);

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
                ? $this->resolutionService->pendingForEnrollment($resolutionEnrollment->id, true)
                : [],
            'helpRequestsCount' => $resolutionEnrollment
                ? $this->resolutionService->pendingCountForEnrollment($resolutionEnrollment->id)
                : 0,
            'defaultSummaryEmail' => AssignmentMailer::resolveStudentEmail($student),
            'summaryEmailRecipients' => $this->notificationEmailService->recipientsForStudent($student),
            'whatsappRecipientCount' => count($this->notificationContactService->recipientsForStudent($student)),
            'formulaDrillSummary' => $this->formulaDrillReport->summaryForStudent($student),
            'enrollmentOptions' => EnrollmentSource::optionsForUi(),
            'coachingClasses' => $coachingClasses,
            'mentor' => $this->mentorService->resolve($student),
        ]);
    }

    public function mapMentor(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'enrollment_source' => ['required', Rule::in(EnrollmentSource::active())],
            'coaching_class_id' => [
                Rule::requiredIf(fn () => $request->input('enrollment_source') === EnrollmentSource::COACHING),
                'nullable',
                'integer',
                Rule::exists('coaching_classes', 'id'),
            ],
            'coaching_class_teacher_id' => [
                Rule::requiredIf(fn () => $request->input('enrollment_source') === EnrollmentSource::COACHING),
                'nullable',
                'integer',
                Rule::exists('coaching_class_teachers', 'id'),
            ],
        ]);

        $this->mentorService->map($student, $validated);

        $enrollment = $student->currentEnrollment();
        if ($enrollment) {
            $enrollment->update([
                'enrollment_source' => $validated['enrollment_source'],
                'coaching_class_id' => $validated['enrollment_source'] === EnrollmentSource::COACHING
                    ? ($validated['coaching_class_id'] ?? null)
                    : null,
            ]);
        }

        return back()->with('success', 'Enrollment and mentor mapping saved.');
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

        if (($student->enrollment_source ?: EnrollmentSource::INDIVIDUAL) === EnrollmentSource::INDIVIDUAL) {
            $this->mentorService->map($student->fresh(), [
                'enrollment_source' => EnrollmentSource::INDIVIDUAL,
            ]);
        }

        return back()->with('success', 'Contact and notification settings saved.');
    }

    public function updateEmails(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'parent1_email' => ['nullable', 'email', 'max:255'],
            'parent2_email' => ['nullable', 'email', 'max:255'],
            'notify_contact_email' => ['sometimes', 'boolean'],
            'notify_login_email' => ['sometimes', 'boolean'],
            'notify_parent1_email' => ['sometimes', 'boolean'],
            'notify_parent2_email' => ['sometimes', 'boolean'],
        ]);

        $student->update($validated);

        return back()->with('success', 'Email notification settings saved.');
    }

    public function progressSummaryPreview(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'mentor_remark' => ['nullable', 'string', 'max:1000'],
            // Legacy alias for older clients.
            'as_of_date' => ['nullable', 'date'],
        ]);

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return response()->json([
                'error' => 'Student has no active enrollment for the current year.',
            ], 422);
        }

        $dateFrom = \Carbon\Carbon::parse($validated['date_from'] ?? $validated['as_of_date']);
        $dateTo = \Carbon\Carbon::parse($validated['date_to'] ?? $validated['as_of_date'] ?? $validated['date_from']);
        $summary = $this->progressSummaryService->build(
            $enrollment,
            $dateTo,
            $dateFrom,
            $validated['mentor_remark'] ?? null,
        );

        return response()->json(['summary' => $summary]);
    }

    public function sendProgressSummary(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'mentor_remark' => ['nullable', 'string', 'max:1000'],
            'send_email' => ['sometimes', 'boolean'],
            'send_whatsapp' => ['sometimes', 'boolean'],
            'email' => ['nullable', 'email', 'max:255'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return back()->with('error', 'Student has no active enrollment for the current year.');
        }

        $dateFrom = \Carbon\Carbon::parse($validated['date_from'] ?? $validated['as_of_date']);
        $dateTo = \Carbon\Carbon::parse($validated['date_to'] ?? $validated['as_of_date'] ?? $validated['date_from']);
        $summary = $this->progressSummaryService->build(
            $enrollment,
            $dateTo,
            $dateFrom,
            $validated['mentor_remark'] ?? null,
        );

        $messages = [];
        $warnings = [];
        $whatsappNotifications = [];

        if ($request->boolean('send_email')) {
            $overrideEmails = filled($validated['email'] ?? null)
                ? [trim($validated['email'])]
                : null;

            $result = StudentProgressMailer::send(
                $student,
                $summary,
                $overrideEmails,
            );

            if ($result['sent']) {
                $messages[] = 'Email sent to '.implode(', ', $result['emails']).'. Admin CC included.';
            } elseif ($result['error'] === 'no_email') {
                $warnings[] = 'No email recipients — add contact/parent emails above or enter an address below.';
            } else {
                $warnings[] = 'Email could not be sent. Check mail settings.';
            }
        }

        if ($request->boolean('send_whatsapp')) {
            if (WhatsAppSender::canAutoSend()) {
                $waResult = StudentProgressWhatsAppMailer::send($student, $summary);

                if ($waResult['sent_count'] > 0) {
                    $messages[] = "WhatsApp sent to {$waResult['sent_count']} recipient(s).";
                }

                if ($waResult['error'] === 'no_recipients') {
                    $warnings[] = 'No WhatsApp recipients — tick Notify on at least one mobile number above and click Save notification settings.';
                } elseif ($waResult['failed_count'] > 0) {
                    $warnings[] = "{$waResult['failed_count']} WhatsApp message(s) could not be sent — use the copy links below to retry.";
                    $whatsappNotifications = collect($waResult['results'])
                        ->reject(fn (array $row) => $row['sent'] ?? false)
                        ->values()
                        ->all();
                }
            } else {
                $whatsappNotifications = $this->progressWhatsAppService->notificationsForSummary($student, $summary);

                if ($whatsappNotifications === []) {
                    $warnings[] = 'No WhatsApp recipients — tick Notify on at least one mobile number above and click Save notification settings.';
                } else {
                    $messages[] = count($whatsappNotifications).' WhatsApp message'.(count($whatsappNotifications) === 1 ? '' : 's').' ready below.';
                }
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

        if ($whatsappNotifications !== []) {
            $redirect = $redirect->with('whatsapp_notifications', $whatsappNotifications);
        }

        return $redirect;
    }

    public function sendPendingWork(Student $student): RedirectResponse
    {
        $result = $this->pendingWorkEmailService->sendToStudent($student);

        if ($result['sent']) {
            $recipientParts = ['to '.implode(', ', $result['to'])];

            if ($result['cc'] !== []) {
                $recipientParts[] = 'cc '.implode(', ', $result['cc']);
            }

            return back()->with(
                'success',
                "Pending worksheet email sent ({$result['balance_count']} item(s)) — ".implode('; ', $recipientParts).'.',
            );
        }

        return match ($result['error']) {
            'no_enrollment' => back()->with('error', 'Student has no active enrollment for the current year.'),
            'no_work' => back()->with('warning', 'This student has no pending worksheets or corrections to email.'),
            'no_email' => back()->with('warning', 'No deliverable email on file — add student or parent email first.'),
            default => back()->with('warning', 'Email could not be sent. Check mail settings on the Notifications page.'),
        };
    }

    public function progressSummaryPdf(Request $request, Student $student)
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'mentor_remark' => ['nullable', 'string', 'max:1000'],
            'as_of_date' => ['nullable', 'date'],
        ]);

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            abort(422, 'Student has no active enrollment for the current year.');
        }

        $dateFrom = \Carbon\Carbon::parse($validated['date_from'] ?? $validated['as_of_date']);
        $dateTo = \Carbon\Carbon::parse($validated['date_to'] ?? $validated['as_of_date'] ?? $validated['date_from']);
        $summary = $this->progressSummaryService->build(
            $enrollment,
            $dateTo,
            $dateFrom,
            $validated['mentor_remark'] ?? null,
        );
        $pdfBytes = $this->progressPdfService->render($summary);
        $filename = $this->progressPdfService->filename($student, $summary);

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}