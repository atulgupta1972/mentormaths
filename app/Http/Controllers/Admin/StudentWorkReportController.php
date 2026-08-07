<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminGradeContext;
use App\Services\ClassAssignmentService;
use App\Services\PendingWorkEmailService;
use App\Services\StudentWorkReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentWorkReportController extends Controller
{
    public function __construct(
        private StudentWorkReportService $reportService,
        private ClassAssignmentService $classAssignmentService,
        private PendingWorkEmailService $pendingWorkEmailService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $boardId = $request->integer('board_id') ?: null;

        $boards = $gradeLevel
            ? $this->classAssignmentService->boardsForGrade($gradeLevel)
            : [];

        if ($gradeLevel && ! $boardId && $boards !== []) {
            $boardId = $this->classAssignmentService->defaultBoardIdForGrade($gradeLevel);
        }

        $report = $gradeLevel
            ? $this->reportService->build($gradeLevel, $boardId)
            : [
                'board_id' => null,
                'live' => [],
                'students' => [],
                'summary' => [
                    'total_students' => 0,
                    'students_with_pending' => 0,
                    'students_live_now' => 0,
                    'students_online' => 0,
                    'total_pending_items' => 0,
                ],
            ];

        return Inertia::render('Admin/StudentWorkReport/Index', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'boards' => $boards,
            'filters' => [
                'board_id' => $boardId,
            ],
            'report' => $report,
        ]);
    }

    public function sendReminders(Request $request): RedirectResponse
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        abort_unless($gradeLevel, 422, 'Select a class from the top bar first.');

        $counts = $this->pendingWorkEmailService->sendToAll(
            $gradeLevel->id,
            skipIfEmpty: true,
        );

        if ($counts['sent'] === 0 && $counts['failed'] === 0) {
            if ($counts['no_work'] > 0) {
                return back()->with('warning', 'No reminders sent — no students in this class have pending work.');
            }

            return back()->with('warning', 'No reminders sent — check student email addresses.');
        }

        $parts = [];

        if ($counts['sent'] > 0) {
            $parts[] = "{$counts['sent']} sent";
        }

        if ($counts['no_work'] > 0) {
            $parts[] = "{$counts['no_work']} skipped (nothing pending)";
        }

        if ($counts['skipped'] > 0) {
            $parts[] = "{$counts['skipped']} skipped (no email)";
        }

        if ($counts['failed'] > 0) {
            $parts[] = "{$counts['failed']} failed";
        }

        $message = 'Pending work reminders: '.implode('; ', $parts).'.';

        return $counts['failed'] > 0
            ? back()->with('warning', $message)
            : back()->with('success', $message);
    }
}
