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
            $validated['chapter_selections'],
        );

        return redirect()->route('student.exams.index')->with('success', 'Exam saved.');
    }

    public function update(Request $request, ExamPlan $examPlan): RedirectResponse
    {
        $this->authorizeStudentPlan($request, $examPlan);

        $validated = $this->validatePlan($request);

        $this->examPlanService->update(
            $examPlan,
            $validated,
            $validated['chapter_selections'],
        );

        return redirect()->route('student.exams.index')->with('success', 'Exam updated.');
    }

    public function destroy(Request $request, ExamPlan $examPlan): RedirectResponse
    {
        $this->authorizeStudentPlan($request, $examPlan);

        $examPlan->delete();

        return redirect()->route('student.exams.index')->with('success', 'Exam removed.');
    }

    public function index(Request $request): \Inertia\Response
    {
        $enrollment = $request->user()->student?->currentEnrollment();
        $enrollment?->loadMissing(['gradeLevel:id,name', 'board:id,name']);

        $examPlans = ['upcoming' => [], 'past' => []];
        $allPlans = [];
        $syllabusChapters = [];
        $examTypeOptions = $this->examPlanService->examTypeOptions();

        if ($enrollment) {
            $plans = $this->examPlanService->plansForEnrollment($enrollment);
            $split = $this->examPlanService->splitPlansByTiming($plans);
            $examPlans = [
                'upcoming' => $split['upcoming']->values()->all(),
                'past' => $split['past']->values()->all(),
            ];
            $allPlans = $plans->values()->all();
            $syllabusChapters = $this->examPlanService->chapterOptionsForEnrollment($enrollment)->values()->all();
        }

        return \Inertia\Inertia::render('Student/Exams/Index', [
            'examPlans' => $examPlans,
            'allPlans' => $allPlans,
            'syllabusChapters' => $syllabusChapters,
            'examTypeOptions' => $examTypeOptions,
            'studyPlanContext' => [
                'grade_name' => $enrollment?->gradeLevel?->name,
                'board_name' => $enrollment?->board?->name,
            ],
        ]);
    }

    private function authorizeStudentPlan(Request $request, ExamPlan $examPlan): void
    {
        $examPlan->loadMissing('enrollment');
        $studentId = $request->user()->student?->id;

        abort_unless(
            $studentId && $examPlan->enrollment?->student_id === $studentId,
            403,
            'You can only manage your own exam plans.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request): array
    {
        $this->normalizeMarkInput($request);

        return $request->validate([
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
        ], [
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
