<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Services\AdminGradeContext;
use App\Services\AssignmentWhatsAppNotificationService;
use App\Services\ClassAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassAssignmentController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private ClassAssignmentService $classAssignmentService,
        private AssignmentWhatsAppNotificationService $whatsappNotifications,
    ) {}

    public function show(Request $request, GradeLevel $gradeLevel): Response
    {
        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        $this->gradeContext->persist($request, $gradeLevel->id);

        $syllabusVersion = $this->classAssignmentService->syllabusForGrade($gradeLevel);
        $assignableChapters = $syllabusVersion
            ? $this->classAssignmentService->assignableChapters($syllabusVersion)
            : [];

        return Inertia::render('Admin/Classes/Assign', [
            'gradeLevel' => $gradeLevel->only(['id', 'name', 'sort_order']),
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
            'syllabusVersion' => $syllabusVersion ? [
                'id' => $syllabusVersion->id,
                'label' => $syllabusVersion->label(),
                'board' => $syllabusVersion->board,
            ] : null,
            'assignableChapters' => $assignableChapters,
            'studentsCount' => $this->classAssignmentService->activeStudentsCount($gradeLevel),
            'gradeLevels' => $this->gradeContext->classLevels()->map->only(['id', 'name']),
        ]);
    }

    public function store(Request $request, GradeLevel $gradeLevel): RedirectResponse
    {
        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'worksheet_ids' => ['required', 'array', 'min:1'],
            'worksheet_ids.*' => ['integer', 'exists:worksheets,id'],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $this->classAssignmentService->assignWorksheetsToClass(
                $gradeLevel,
                $validated['worksheet_ids'],
                $request->user(),
                $validated['target_date'],
                $validated['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $sheetCount = $result['worksheets_assigned'];
        $studentCount = count($result['students_notified']);
        $message = "Assigned {$sheetCount} sheet".($sheetCount === 1 ? '' : 's')
            ." to {$gradeLevel->name} ({$studentCount} student".($studentCount === 1 ? '' : 's').")."
            ." Target: {$validated['target_date']}.";

        if ($result['skipped'] > 0) {
            $message .= " Skipped {$result['skipped']} duplicate assignment(s).";
        }

        $whatsappNotifications = $this->whatsappNotifications->notificationsForClassMultiAssignment(
            $result['worksheets_by_student'],
            $validated['target_date'],
            $validated['notes'] ?? null,
        );

        $warnings = [];
        if ($result['errors']) {
            $warnings[] = implode(' ', array_slice($result['errors'], 0, 3));
        }
        if ($whatsappNotifications === [] && $studentCount > 0) {
            $warnings[] = 'WhatsApp not opened — no notify-enabled contacts for assigned student(s).';
        }

        $redirect = back()->with('success', $message);

        if ($warnings !== []) {
            $redirect = $redirect->with('warning', implode(' ', $warnings));
        }

        if ($whatsappNotifications !== []) {
            return $redirect->with('whatsapp_notifications', $whatsappNotifications);
        }

        return $redirect;
    }
}
