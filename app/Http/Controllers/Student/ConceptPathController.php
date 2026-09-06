<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\TextbookChapter;
use App\Services\StudentConceptPathService;
use App\Support\ConceptPathStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConceptPathController extends Controller
{
    public function __construct(
        private StudentConceptPathService $conceptPathLearn,
    ) {}

    public function show(Request $request, TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        $user = $request->user();

        try {
            $progress = $this->conceptPathLearn->startOrResume($user, $textbookChapter);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('student.school-study-plan.show')
                ->with('error', $e->getMessage());
        }

        $textbookChapter->load([
            'textbook.gradeLevel:id,name',
            'syllabusChapter:id,name,chapter_number',
        ]);

        $cards = $this->conceptPathLearn->playCards($textbookChapter);
        $items = is_array($textbookChapter->concept_path_items) ? $textbookChapter->concept_path_items : [];

        return Inertia::render('Student/ConceptPathLearn', [
            'chapter' => [
                'id' => $textbookChapter->id,
                'label' => $textbookChapter->displaySyllabusLabel(),
                'title' => $textbookChapter->displayTitle(),
                'chapter_number' => $textbookChapter->displayChapterNumber(),
                'book_name' => $textbookChapter->textbook?->name,
                'book_code' => $textbookChapter->textbook?->code,
                'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                'study_plan_url' => route('student.school-study-plan.show'),
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
                'record_card' => route('student.concept-path.record-card', $textbookChapter),
                'complete' => route('student.concept-path.complete', $textbookChapter),
            ],
        ]);
    }

    public function recordCard(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $validated = $request->validate([
            'card_index' => ['required', 'integer', 'min:0'],
            'card_step' => ['nullable', 'integer', 'min:1'],
            'card_type' => ['nullable', 'string', 'max:32'],
            'correct' => ['nullable', 'boolean'],
            'action' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $this->conceptPathLearn->recordCard($request->user(), $textbookChapter, $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function complete(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        try {
            $this->conceptPathLearn->complete($request->user(), $textbookChapter);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Great work — you finished learning the concepts for this chapter.');
    }
}
