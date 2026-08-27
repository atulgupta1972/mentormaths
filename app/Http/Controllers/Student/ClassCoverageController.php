<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\SyllabusChapter;
use App\Services\ClassCoverageService;
use App\Services\ExamPlanService;
use App\Services\SetAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassCoverageController extends Controller
{
    public function __construct(
        private ClassCoverageService $coverageService,
        private ExamPlanService $examPlanService,
        private SetAssignmentService $assignmentService,
    ) {}

    public function show(Request $request): Response
    {
        $enrollment = $request->user()->student?->currentEnrollment();
        $enrollment?->loadMissing(['gradeLevel:id,name', 'board:id,name']);
        $examPlans = ['upcoming' => [], 'past' => []];
        if ($enrollment) {
            $plans = $this->examPlanService->plansForEnrollment($enrollment);
            $split = $this->examPlanService->splitPlansByTiming($plans);
            $examPlans = [
                'upcoming' => $split['upcoming']->values()->all(),
                'past' => $split['past']->values()->all(),
            ];
        }

        return Inertia::render('Student/SchoolStudyPlan', [
            'classCoverage' => $this->coverageService->forEnrollment($enrollment),
            'upcomingExams' => $examPlans['upcoming'],
            'context' => [
                'grade_name' => $enrollment?->gradeLevel?->name,
                'board_name' => $enrollment?->board?->name,
            ],
        ]);
    }

    public function update(Request $request, SyllabusChapter $syllabusChapter): RedirectResponse
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment) {
            return back()->with('error', 'No active enrollment for this year.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:studied,under_study,none'],
        ]);

        match ($validated['status']) {
            'under_study' => $this->coverageService->markUnderStudy($enrollment, $syllabusChapter),
            'studied' => $this->coverageService->markStudied($enrollment, $syllabusChapter),
            'none' => $this->coverageService->clearCoverage($enrollment, $syllabusChapter),
        };

        $message = match ($validated['status']) {
            'under_study', 'studied' => 'Study plan updated. Chapter sets are due today — open them from To do or drill down.',
            default => 'School study plan updated.',
        };

        return back()->with('success', $message);
    }

    /**
     * Move an assigned sheet into a home syllabus chapter, or leave it in Additional (null).
     */
    public function updateAssignmentChapter(Request $request, SetAssignment $assignment): RedirectResponse
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment || (int) $assignment->student_enrollment_id !== (int) $enrollment->id) {
            abort(403, 'This assignment is not on your study plan.');
        }

        $validated = $request->validate([
            'effective_syllabus_chapter_id' => ['nullable', 'integer', 'exists:syllabus_chapters,id'],
        ]);

        try {
            $this->assignmentService->updateEffectiveChapter(
                $assignment,
                isset($validated['effective_syllabus_chapter_id'])
                    ? (int) $validated['effective_syllabus_chapter_id']
                    : null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $moved = isset($validated['effective_syllabus_chapter_id']);

        return back()->with(
            'success',
            $moved
                ? 'Sheet moved to that chapter on your study plan.'
                : 'Sheet left in Additional.',
        );
    }
}
