<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Services\SetAttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PracticeSetController extends Controller
{
    public function __construct(private SetAttemptService $attemptService) {}

    public function showAssignment(Request $request, SetAssignment $assignment): Response|RedirectResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $assignment->load([
            'practiceSet.topic.chapter',
            'practiceSet' => fn ($q) => $q->withCount('questions'),
            'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
        ]);

        $inProgress = $assignment->attempts->firstWhere('status', SetAttempt::STATUS_IN_PROGRESS);

        return Inertia::render('Student/PracticeSets/Assignment', [
            'assignment' => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'notes' => $assignment->notes,
                'target_date' => $assignment->due_date?->toDateString(),
                'is_overdue' => $assignment->isOverdue(),
                'practice_set' => $assignment->practiceSet,
                'attempts' => $assignment->attempts->map(fn ($a) => [
                    'id' => $a->id,
                    'attempt_number' => $a->attempt_number,
                    'status' => $a->status,
                    'score' => $a->score,
                    'max_score' => $a->max_score,
                    'time_seconds' => $a->time_seconds,
                    'submission_timing' => $a->submission_timing,
                    'completed_at' => $a->completed_at?->toDateTimeString(),
                ]),
                'in_progress_attempt_id' => $inProgress?->id,
            ],
        ]);
    }

    public function startAttempt(Request $request, SetAssignment $assignment): RedirectResponse
    {
        $this->authorizeAssignment($request, $assignment);

        if ($assignment->practiceSet->status !== 'published') {
            return back()->with('error', 'This practice set is not available.');
        }

        try {
            $attempt = $this->attemptService->start($assignment);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('student.attempts.show', $attempt);
    }

    public function showAttempt(Request $request, SetAttempt $attempt): Response|RedirectResponse
    {
        $assignment = $attempt->assignment()->with([
            'practiceSet.questions.options',
            'practiceSet.topic',
        ])->first();

        $this->authorizeAssignment($request, $assignment);

        if ($attempt->status === SetAttempt::STATUS_SUBMITTED) {
            return redirect()->route('student.attempts.result', $attempt);
        }

        $questions = $assignment->practiceSet->questions->map(fn ($q) => [
            'id' => $q->id,
            'question_text' => $q->question_text,
            'options' => $q->options->map(fn ($o) => [
                'id' => $o->id,
                'option_text' => $o->option_text,
            ]),
        ]);

        return Inertia::render('Student/PracticeSets/Attempt', [
            'attempt' => [
                'id' => $attempt->id,
                'started_at' => $attempt->started_at->toIso8601String(),
            ],
            'practiceSet' => [
                'display_title' => $assignment->practiceSet->display_title,
                'tier_label' => $assignment->practiceSet->tier_label,
                'topic_name' => $assignment->practiceSet->topic?->name,
            ],
            'questions' => $questions,
        ]);
    }

    public function submitAttempt(Request $request, SetAttempt $attempt): RedirectResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'answers.*' => ['nullable', 'integer'],
        ]);

        try {
            $this->attemptService->submit($attempt, $validated['answers']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('student.attempts.result', $attempt)
            ->with('success', 'Practice set submitted!');
    }

    public function result(Request $request, SetAttempt $attempt): Response
    {
        $assignment = $attempt->assignment()->with([
            'practiceSet.topic',
            'practiceSet.questions.options',
        ])->first();

        $this->authorizeAssignment($request, $assignment);

        $attempt->load('answers');

        $questions = $assignment->practiceSet->questions->map(function ($q) use ($attempt) {
            $answer = $attempt->answers->firstWhere('question_id', $q->id);
            $correct = $q->options->firstWhere('is_correct', true);

            return [
                'question_text' => $q->question_text,
                'explanation' => $q->explanation,
                'is_correct' => $answer?->is_correct ?? false,
                'selected_option_id' => $answer?->question_option_id,
                'correct_option_id' => $correct?->id,
                'options' => $q->options->map(fn ($o) => [
                    'id' => $o->id,
                    'option_text' => $o->option_text,
                    'is_correct' => $o->is_correct,
                ]),
            ];
        });

        return Inertia::render('Student/PracticeSets/Result', [
            'attempt' => [
                'id' => $attempt->id,
                'score' => $attempt->score,
                'max_score' => $attempt->max_score,
                'time_seconds' => $attempt->time_seconds,
                'attempt_number' => $attempt->attempt_number,
                'completed_at' => $attempt->completed_at?->toDateTimeString(),
                'submission_timing' => $attempt->submission_timing,
            ],
            'assignment' => [
                'target_date' => $assignment->due_date?->toDateString(),
            ],
            'practiceSet' => [
                'display_title' => $assignment->practiceSet->display_title,
                'topic_name' => $assignment->practiceSet->topic?->name,
            ],
            'questions' => $questions,
        ]);
    }

    private function authorizeAssignment(Request $request, SetAssignment $assignment): void
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment || $assignment->student_enrollment_id !== $enrollment->id) {
            abort(403);
        }
    }
}
