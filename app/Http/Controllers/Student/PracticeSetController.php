<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\QuestionResolutionItem;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Services\GuidedPracticeService;
use App\Services\QuestionResolutionService;
use App\Services\SetAttemptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PracticeSetController extends Controller
{
    public function __construct(
        private SetAttemptService $attemptService,
        private GuidedPracticeService $guidedPractice,
        private QuestionResolutionService $resolutionService,
    ) {}

    public function showAssignment(Request $request, SetAssignment $assignment): Response|RedirectResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $assignment->load([
            'practiceSet' => fn ($q) => $q->withCount('questions'),
            'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
        ]);

        $inProgress = $assignment->attempts->firstWhere('status', SetAttempt::STATUS_IN_PROGRESS);
        $practiceSet = $assignment->practiceSet;

        return Inertia::render('Student/PracticeSets/Assignment', [
            'assignment' => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'notes' => $assignment->notes,
                'target_date' => $assignment->due_date?->toDateString(),
                'is_overdue' => $assignment->isOverdue(),
                'is_guided' => ! $practiceSet->isChapterScope(),
                'practice_set' => [
                    'set_code' => $practiceSet->set_code,
                    'set_number' => $practiceSet->set_number,
                    'kind_label' => $practiceSet->isChapterScope() ? 'Test' : 'Practice',
                ],
                'attempts' => $assignment->attempts->map(fn ($a) => [
                    'id' => $a->id,
                    'attempt_number' => $a->attempt_number,
                    'status' => $a->status,
                    'mode' => $a->mode,
                    'score' => $a->score,
                    'max_score' => $a->max_score,
                    'first_try_correct_count' => $a->first_try_correct_count,
                    'corrected_after_help_count' => $a->corrected_after_help_count,
                    'given_up_count' => $a->given_up_count,
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
        $assignment = $attempt->assignment()->with('practiceSet')->first();
        $this->authorizeAssignment($request, $assignment);

        if ($attempt->status === SetAttempt::STATUS_SUBMITTED) {
            return redirect()->route('student.attempts.result', $attempt);
        }

        $this->attemptService->ensureGuidedForTopicPractice($attempt);
        $attempt->refresh();

        if ($attempt->isGuided()) {
            return Inertia::render('Student/PracticeSets/GuidedAttempt', $this->guidedPractice->buildPayload($attempt));
        }

        $assignment->load(['practiceSet.questions.options']);
        $practiceSet = $assignment->practiceSet;
        $questions = $practiceSet->questions->values()->map(function ($q, $index) {
            return [
                'id' => $q->id,
                'number' => $index + 1,
                'question_text' => $q->question_text,
                'diagram_url' => $q->diagram_url,
                'options' => $q->options->values()->map(function ($o, $optionIndex) {
                    return [
                        'id' => $o->id,
                        'letter' => chr(65 + $optionIndex),
                        'option_text' => $o->option_text,
                    ];
                }),
            ];
        });

        return Inertia::render('Student/PracticeSets/Attempt', [
            'attempt' => [
                'id' => $attempt->id,
                'started_at' => $attempt->started_at->toIso8601String(),
            ],
            'practiceSet' => [
                'set_code' => $practiceSet->set_code,
                'set_number' => $practiceSet->set_number,
                'kind_label' => $practiceSet->isChapterScope() ? 'Test' : 'Practice',
            ],
            'referencePdfUrl' => $this->referencePdfUrlFor($assignment),
            'questions' => $questions,
        ]);
    }

    public function guidedAnswer(Request $request, SetAttempt $attempt): RedirectResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        $attempt->loadMissing([
            'guidedQuestions.question',
        ]);
        $current = $attempt->guidedQuestions->firstWhere('sort_order', $attempt->current_question_index);
        $question = $current?->question;

        $validated = $request->validate([
            'option_id' => ['nullable', 'integer'],
            'answer_text' => ['nullable', 'string', 'max:64'],
        ]);

        if ($question?->isFillInBlank()) {
            if (! filled($validated['answer_text'] ?? null)) {
                return back()->with('error', 'Enter an answer before submitting.');
            }
        } elseif (! ($validated['option_id'] ?? null)) {
            return back()->with('error', 'Select an option before submitting.');
        }

        try {
            $payload = $this->guidedPractice->submitAnswer(
                $attempt,
                $validated['option_id'] ?? null,
                $validated['answer_text'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($attempt->fresh()->status === SetAttempt::STATUS_SUBMITTED) {
            return redirect()->route('student.attempts.result', $attempt);
        }

        return back()->with('guided_feedback', $payload['feedback'] ?? null);
    }

    public function guidedGiveUp(Request $request, SetAttempt $attempt): RedirectResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        try {
            $this->guidedPractice->giveUp($attempt);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($attempt->fresh()->status === SetAttempt::STATUS_SUBMITTED) {
            return redirect()->route('student.attempts.result', $attempt);
        }

        return back();
    }

    public function submitAttempt(Request $request, SetAttempt $attempt): RedirectResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        if ($attempt->isGuided()) {
            abort(404);
        }

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
            ->with('success', 'Submitted successfully!');
    }

    public function result(Request $request, SetAttempt $attempt): Response
    {
        $assignment = $attempt->assignment()->with([
            'practiceSet.questions',
        ])->first();

        $this->authorizeAssignment($request, $assignment);

        $attempt->load(['answers', 'guidedQuestions']);

        $practiceSet = $assignment->practiceSet;

        if ($attempt->isGuided()) {
            return Inertia::render('Student/PracticeSets/GuidedResult', [
                'attempt' => [
                    'id' => $attempt->id,
                    'attempt_number' => $attempt->attempt_number,
                    'completed_at' => $attempt->completed_at?->toDateTimeString(),
                    'time_seconds' => $attempt->time_seconds,
                    'submission_timing' => $attempt->submission_timing,
                    'first_try_correct' => $attempt->first_try_correct_count ?? 0,
                    'max_score' => $attempt->max_score ?? 0,
                    'corrected_after_help' => $attempt->corrected_after_help_count ?? 0,
                    'given_up' => $attempt->given_up_count ?? 0,
                ],
                'assignment' => [
                    'target_date' => $assignment->due_date?->toDateString(),
                ],
                'practiceSet' => [
                    'set_code' => $practiceSet->set_code,
                    'set_number' => $practiceSet->set_number,
                    'kind_label' => 'Practice',
                ],
            ]);
        }

        $questions = $practiceSet->questions->values()->map(function ($q, $index) use ($attempt) {
            $answer = $attempt->answers->firstWhere('question_id', $q->id);

            return [
                'number' => $index + 1,
                'is_correct' => $answer?->is_correct ?? false,
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
                'set_code' => $practiceSet->set_code,
                'set_number' => $practiceSet->set_number,
                'kind_label' => $practiceSet->isChapterScope() ? 'Test' : 'Practice',
            ],
            'referencePdfUrl' => $this->referencePdfUrlFor($assignment),
            'questions' => $questions,
        ]);
    }

    public function showResolution(Request $request, QuestionResolutionItem $item): Response|RedirectResponse
    {
        $this->authorizeResolution($request, $item);

        if ($item->status !== QuestionResolutionItem::STATUS_PENDING) {
            return redirect()->route('dashboard')->with('success', 'This sum is already resolved.');
        }

        return Inertia::render('Student/PracticeSets/Resolution', [
            'item' => $this->resolutionService->formatItem($item),
        ]);
    }

    public function submitResolution(Request $request, QuestionResolutionItem $item): RedirectResponse
    {
        $this->authorizeResolution($request, $item);

        $validated = $request->validate([
            'option_id' => ['nullable', 'integer'],
            'answer_text' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $this->resolutionService->submitAnswer(
                $item,
                $validated['option_id'] ?? null,
                $validated['answer_text'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['resolved']) {
            return redirect()->route('dashboard')->with('success', $result['message']);
        }

        return back()->with('warning', $result['message']);
    }

    private function authorizeAssignment(Request $request, SetAssignment $assignment): void
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment || $assignment->student_enrollment_id !== $enrollment->id) {
            abort(403);
        }
    }

    private function authorizeResolution(Request $request, QuestionResolutionItem $item): void
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment || $item->student_enrollment_id !== $enrollment->id) {
            abort(403);
        }
    }

    private function referencePdfUrlFor(SetAssignment $assignment): ?string
    {
        $assignment->loadMissing('practiceSet.topic');

        return $assignment->practiceSet->topic?->reference_pdf_url;
    }
}
