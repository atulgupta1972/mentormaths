<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\ExamPlan;
use App\Services\ExamPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExamPlanController extends Controller
{
    public function __construct(private ExamPlanService $examPlanService) {}

    public function store(Request $request): RedirectResponse
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment) {
            return back()->with('error', 'No active enrollment for this year.');
        }

        $validated = $this->validatePlan($request);

        $this->examPlanService->create(
            $enrollment,
            $request->user(),
            $validated,
            $validated['syllabus_chapter_ids'],
        );

        return back()->with('success', 'Exam plan saved.');
    }

    public function update(Request $request, ExamPlan $examPlan): RedirectResponse
    {
        $this->authorizeStudentPlan($request, $examPlan);

        $validated = $this->validatePlan($request);

        $this->examPlanService->update(
            $examPlan,
            $validated,
            $validated['syllabus_chapter_ids'],
        );

        return back()->with('success', 'Exam plan updated.');
    }

    public function destroy(Request $request, ExamPlan $examPlan): RedirectResponse
    {
        $this->authorizeStudentPlan($request, $examPlan);

        $examPlan->delete();

        return back()->with('success', 'Exam plan removed.');
    }

    private function authorizeStudentPlan(Request $request, ExamPlan $examPlan): void
    {
        $studentId = $request->user()->student?->id;

        abort_unless(
            $studentId && $examPlan->enrollment?->student_id === $studentId,
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request): array
    {
        return $request->validate([
            'exam_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', 'in:unit_test,half_yearly,final,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'syllabus_chapter_ids' => ['required', 'array', 'min:1'],
            'syllabus_chapter_ids.*' => ['integer', 'exists:syllabus_chapters,id'],
        ]);
    }
}
