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
            $validated['chapter_selections'],
        );

        return back()->with('success', "Exam plan saved for {$student->name}.");
    }

    public function update(Request $request, ExamPlan $examPlan): RedirectResponse
    {
        $validated = $this->validatePlan($request, false);

        $this->examPlanService->update(
            $examPlan,
            $validated,
            $validated['chapter_selections'],
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
        $this->normalizeMarkInput($request);

        $rules = [
            'exam_date' => ['required', 'date'],
            'title' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', 'in:unit_test,half_yearly,final,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'obtained_marks' => ['nullable', 'integer', 'min:0', 'required_with:total_marks'],
            'total_marks' => ['nullable', 'integer', 'min:1', 'required_with:obtained_marks'],
            'chapter_selections' => ['required', 'array', 'min:1'],
            'chapter_selections.*.syllabus_chapter_id' => ['required', 'integer', 'exists:syllabus_chapters,id'],
            'chapter_selections.*.syllabus_topic_ids' => ['nullable', 'array'],
            'chapter_selections.*.syllabus_topic_ids.*' => ['integer', 'exists:syllabus_topics,id'],
        ];

        if ($requireStudent) {
            $rules['student_id'] = ['required', 'exists:students,id'];
        }

        return $request->validate($rules, [
            'obtained_marks.required_with' => 'Enter both marks obtained and total marks.',
            'total_marks.required_with' => 'Enter both marks obtained and total marks.',
        ]);
    }

    private function normalizeMarkInput(Request $request): void
    {
        foreach (['obtained_marks', 'total_marks'] as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        if ($request->filled('obtained_marks') && $request->filled('total_marks')
            && (int) $request->input('obtained_marks') > (int) $request->input('total_marks')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'obtained_marks' => 'Marks obtained cannot be more than total marks.',
            ]);
        }
    }
}
