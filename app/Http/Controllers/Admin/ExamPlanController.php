<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExamPlan;
use App\Models\Student;
use App\Services\ExamPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExamPlanController extends Controller
{
    public function __construct(private ExamPlanService $examPlanService) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request, true);

        $student = Student::findOrFail($validated['student_id']);
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return back()->with('error', 'Student has no active enrollment for the current year.');
        }

        $this->examPlanService->create(
            $enrollment,
            $request->user(),
            $validated,
            $validated['syllabus_chapter_ids'],
        );

        return back()->with('success', "Exam plan saved for {$student->name}.");
    }

    public function update(Request $request, ExamPlan $examPlan): RedirectResponse
    {
        $validated = $this->validatePlan($request, false);

        $this->examPlanService->update(
            $examPlan,
            $validated,
            $validated['syllabus_chapter_ids'],
        );

        return back()->with('success', 'Exam plan updated.');
    }

    public function destroy(ExamPlan $examPlan): RedirectResponse
    {
        $examPlan->delete();

        return back()->with('success', 'Exam plan removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request, bool $requireStudent): array
    {
        $rules = [
            'exam_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', 'in:unit_test,half_yearly,final,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'syllabus_chapter_ids' => ['required', 'array', 'min:1'],
            'syllabus_chapter_ids.*' => ['integer', 'exists:syllabus_chapters,id'],
        ];

        if ($requireStudent) {
            $rules['student_id'] = ['required', 'exists:students,id'];
        }

        return $request->validate($rules);
    }
}
