<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\QuestionResolutionItem;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Services\GuidedPracticeService;
use App\Services\QuestionResolutionService;
use App\Services\SetAttemptService;
use App\Support\AttemptIntegrity;
use App\Support\AttemptResultSummary;
use App\Support\AttemptTiming;
use Illuminate\Http\JsonResponse;
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

        if ($assignment->practiceSet->isWritten()) {
            return redirect()->route('student.written-assignments.show', $assignment);
        }

        $inProgress = $assignment->attempts->firstWhere('status', SetAttempt::STATUS_IN_PROGRESS);
        $latestSubmitted = $assignment->attempts->firstWhere('status', SetAttempt::STATUS_SUBMITTED);
        $practiceSet = $assignment->practiceSet;
        $enrollment = $request->user()->student?->currentEnrollment();
        $enrollment?->loadMissing('gradeLevel:id,name,protect_test_attempts,protect_practice_attempts');
        $isTest = $practiceSet->isChapterScope();

        return Inertia::render('Student/PracticeSets/Assignment', [
            'assignment' => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'notes' => $assignment->notes,
                'target_date' => $assignment->due_date?->toDateString(),
                'is_overdue' => $assignment->isOverdue(),
                'is_guided' => ! $isTest,
                'latest_attempt_id' => $latestSubmitted?->id,
                'integrity' => AttemptIntegrity::configFor($enrollment, $isTest),
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
            $this->guidedPractice->ensureAttemptReady($attempt);
            $attempt->refresh();
        }

        if ($attempt->status === SetAttempt::STATUS_SUBMITTED) {
            return redirect()->route('student.attempts.result', $attempt);
        }

        if ($attempt->status === SetAttempt::STATUS_IN_PROGRESS) {
            AttemptTiming::resumeSession($attempt);
            $attempt->refresh();
        }

        if ($attempt->isGuided()) {
            return Inertia::render('Student/PracticeSets/GuidedAttempt', [
                ...$this->guidedPractice->buildPayload($attempt),
                'integrity' => AttemptIntegrity::payloadForAttempt($attempt, false),
            ]);
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
                ...AttemptTiming::payloadForAttempt($attempt),
            ],
            'integrity' => AttemptIntegrity::payloadForAttempt($attempt, true),
            'practiceSet' => [
                'set_code' => $practiceSet->set_code,
                'set_number' => $practiceSet->set_number,
                'kind_label' => $practiceSet->isChapterScope() ? 'Test' : 'Practice',
            ],
            'referencePdfUrl' => $this->referencePdfUrlFor($assignment),
            'questions' => $questions,
        ]);
    }

    public function pauseAttemptTiming(Request $request, SetAttempt $attempt): JsonResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        if ($attempt->status === SetAttempt::STATUS_IN_PROGRESS) {
            AttemptTiming::pauseSession($attempt);
            $attempt->refresh();
        }

        return response()->json(AttemptTiming::payloadForAttempt($attempt));
    }

    public function recordTabLeave(Request $request, SetAttempt $attempt): JsonResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        if ($attempt->status === SetAttempt::STATUS_IN_PROGRESS) {
            $this->attemptService->recordTabLeave($attempt);
            $attempt->refresh();
        }

        return response()->json([
            'tab_leave_count' => $attempt->tab_leave_count ?? 0,
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

        return $this->guidedAttemptRedirect($attempt, $payload['feedback'] ?? null);
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

        return $this->guidedAttemptRedirect($attempt, null, 'Help requested — your teacher will explain this sum. It is on your dashboard help list.');
    }

    public function guidedRequestHint(Request $request, SetAttempt $attempt): RedirectResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        try {
            $payload = $this->guidedPractice->requestEarlyHint($attempt);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->guidedAttemptRedirect($attempt, $payload['feedback'] ?? null);
    }

    private function guidedAttemptRedirect(
        SetAttempt $attempt,
        ?array $feedback = null,
        ?string $success = null,
    ): RedirectResponse {
        $redirect = redirect()
            ->route('student.attempts.show', $attempt)
            ->with('guided_feedback', $feedback);

        if ($success) {
            $redirect = $redirect->with('success', $success);
        }

        return $redirect;
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
            $review = AttemptResultSummary::forStudentReview($attempt);

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
                'questions' => $review['questions'],
            ]);
        }

        $review = AttemptResultSummary::forStudentReview($attempt);

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
            'questions' => $review['questions'],
        ]);
    }

    public function practiceRetry(Request $request, SetAttempt $attempt): JsonResponse
    {
        $assignment = $attempt->assignment;
        $this->authorizeAssignment($request, $assignment);

        $validated = $request->validate([
            'question_id' => ['required', 'integer'],
            'option_id' => ['nullable', 'integer'],
            'answer_text' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $result = $this->attemptService->checkPracticeRetry(
                $attempt,
                (int) $validated['question_id'],
                isset($validated['option_id']) ? (int) $validated['option_id'] : null,
                $validated['answer_text'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function showResolution(Request $request, QuestionResolutionItem $item): Response|RedirectResponse
    {
        $this->authorizeResolution($request, $item);

        if ($item->status !== QuestionResolutionItem::STATUS_PENDING) {
            return redirect()->route('dashboard')->with('success', 'This sum is already resolved.');
        }

        $inQueue = $request->query('queue') === 'all';
        $queueMeta = $inQueue ? $this->resolutionService->queueMetaForItem($item) : null;

        return Inertia::render('Student/PracticeSets/Resolution', [
            'item' => $this->resolutionService->formatItem($item),
            'inQueue' => $inQueue,
            'queuePosition' => $queueMeta['position'] ?? null,
            'queueTotal' => $queueMeta['total'] ?? null,
        ]);
    }

    public function startClearAllQueue(Request $request): RedirectResponse
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment) {
            abort(403);
        }

        $first = $this->resolutionService->firstPendingForEnrollment($enrollment->id);

        if (! $first) {
            return redirect()->route('dashboard')->with('warning', 'No pending help requests to clear.');
        }

        $request->session()->put('doubt_clear_batch', [
            'enrollment_id' => $enrollment->id,
            'cleared_ids' => [],
        ]);

        return redirect()->route('student.resolutions.show', [
            'item' => $first->id,
            'queue' => 'all',
        ]);
    }

    public function resolutionHistory(Request $request): Response
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment) {
            abort(403);
        }

        return Inertia::render('Student/PracticeSets/ResolutionHistory', [
            'items' => $this->resolutionService->historyForEnrollment($enrollment->id),
        ]);
    }

    public function submitResolution(Request $request, QuestionResolutionItem $item): RedirectResponse
    {
        $this->authorizeResolution($request, $item);

        $validated = $request->validate([
            'option_id' => ['nullable', 'integer'],
            'answer_text' => ['nullable', 'string', 'max:64'],
            'queue' => ['nullable', 'string'],
        ]);

        $inQueue = ($validated['queue'] ?? null) === 'all';

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
            $message = $result['message'];
            $student = $request->user()->student;
            $enrollment = $student?->currentEnrollment();

            if ($inQueue) {
                $batch = $request->session()->get('doubt_clear_batch', [
                    'enrollment_id' => $enrollment?->id,
                    'cleared_ids' => [],
                ]);
                $batch['cleared_ids'][] = $item->id;
                $request->session()->put('doubt_clear_batch', $batch);

                $next = $enrollment
                    ? $this->resolutionService->nextPendingAfter($enrollment->id, $item->id)
                    : null;

                if ($next) {
                    return redirect()->route('student.resolutions.show', [
                        'item' => $next->id,
                        'queue' => 'all',
                    ])->with('success', $message);
                }

                $request->session()->forget('doubt_clear_batch');

                if ($student && $batch['cleared_ids'] !== []) {
                    $emailResult = $this->resolutionService->sendClearanceEmailForItems(
                        $student,
                        $batch['cleared_ids'],
                    );

                    if ($emailResult['sent']) {
                        $message .= ' Confirmation email sent to you and admin.';
                    }
                }

                return redirect()->route('dashboard')->with(
                    'success',
                    $message.' All doubts cleared — well done!',
                );
            }

            if ($student && $enrollment && $this->resolutionService->pendingCountForEnrollment($enrollment->id) === 0) {
                $emailResult = $this->resolutionService->sendClearanceEmailForItems($student, [$item->id]);

                if ($emailResult['sent']) {
                    $message .= ' Confirmation email sent to you and admin.';
                }
            }

            return redirect()->route('dashboard')->with('success', $message);
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
