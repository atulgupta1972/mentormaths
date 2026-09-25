<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\TextbookChapter;
use App\Services\StudentConceptPathService;
use App\Support\ConceptPathStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudentConceptPathController extends Controller
{
    public function __construct(
        private StudentConceptPathService $conceptPathLearn,
    ) {}

    public function show(Request $request, Student $student, TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        $staff = $request->user();

        try {
            $learner = $this->conceptPathLearn->assertStaffCanRunForStudent($staff, $student, $textbookChapter);
            $progress = $this->conceptPathLearn->startOrResumeForLearner($learner, $textbookChapter);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('admin.school-study-plan.index', ['student_id' => $student->id])
                ->with('error', $e->getMessage());
        }

        $textbookChapter->load([
            'textbook.gradeLevel:id,name',
            'syllabusChapter:id,name,chapter_number',
        ]);

        $cards = $this->conceptPathLearn->playCards($textbookChapter);
        $items = is_array($textbookChapter->concept_path_items) ? $textbookChapter->concept_path_items : [];

        return Inertia::render('Student/ConceptPathLearn', [
            'guidedByStaff' => true,
            'studentName' => $student->name,
            'chapter' => [
                'id' => $textbookChapter->id,
                'label' => $textbookChapter->displaySyllabusLabel(),
                'title' => $textbookChapter->displayTitle(),
                'chapter_number' => $textbookChapter->displayChapterNumber(),
                'book_name' => $textbookChapter->textbook?->name,
                'book_code' => $textbookChapter->textbook?->code,
                'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                'study_plan_url' => route('admin.school-study-plan.index', ['student_id' => $student->id]),
            ],
            'path' => [
                'chapter_title' => $items['chapter_title'] ?? $textbookChapter->displayTitle(),
                'status' => $textbookChapter->concept_path_status,
                'status_label' => ConceptPathStatus::label($textbookChapter->concept_path_status),
                'cards' => $cards,
            ],
            'progress' => [
                'status' => $progress->status,
                'cards_total' => (int) $progress->cards_total,
                'cards_completed' => (int) $progress->cards_completed,
                'current_card_index' => (int) $progress->current_card_index,
                'started_at' => $progress->started_at?->toIso8601String(),
                'completed_at' => $progress->completed_at?->toIso8601String(),
            ],
            'routes' => [
                'record_card' => route('admin.students.concept-path.record-card', [
                    'student' => $student->id,
                    'textbookChapter' => $textbookChapter->id,
                ]),
                'complete' => route('admin.students.concept-path.complete', [
                    'student' => $student->id,
                    'textbookChapter' => $textbookChapter->id,
                ]),
            ],
        ]);
    }

    public function recordCard(Request $request, Student $student, TextbookChapter $textbookChapter): RedirectResponse
    {
        $validated = $request->validate([
            'card_index' => ['required', 'integer', 'min:0'],
            'card_step' => ['nullable', 'integer', 'min:1'],
            'card_type' => ['nullable', 'string', 'max:32'],
            'correct' => ['nullable', 'boolean'],
            'action' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $learner = $this->conceptPathLearn->assertStaffCanRunForStudent(
                $request->user(),
                $student,
                $textbookChapter,
            );
            $this->conceptPathLearn->recordCardForLearner($learner, $textbookChapter, $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function complete(Request $request, Student $student, TextbookChapter $textbookChapter): RedirectResponse
    {
        try {
            $learner = $this->conceptPathLearn->assertStaffCanRunForStudent(
                $request->user(),
                $student,
                $textbookChapter,
            );
            $this->conceptPathLearn->completeForLearner($learner, $textbookChapter);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.school-study-plan.index', ['student_id' => $student->id])
            ->with('success', "Concept learning marked done for {$student->name}.");
    }
}
