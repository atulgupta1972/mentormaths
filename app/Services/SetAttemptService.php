<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use App\Models\StudentEnrollment;
use App\Support\AssignmentMailer;
use App\Support\AssignmentProgress;
use App\Support\AttemptResultSummary;
use App\Support\AttemptTiming;
use App\Support\AnswerValidationService;
use Illuminate\Support\Facades\DB;

class SetAttemptService
{
    public function __construct(
        private GuidedPracticeService $guidedPractice,
        private AnswerValidationService $answerValidation,
    ) {}

    public function start(SetAssignment $assignment): SetAttempt
    {
        $inProgress = $assignment->attempts()
            ->where('status', SetAttempt::STATUS_IN_PROGRESS)
            ->first();

        if ($inProgress) {
            $this->ensureGuidedForTopicPractice($inProgress);

            return $inProgress->fresh();
        }

        if ($assignment->status === SetAssignment::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Ask your teacher to allow another attempt.');
        }

        $nextNumber = ($assignment->attempts()->max('attempt_number') ?? 0) + 1;

        $assignment->loadMissing('practiceSet');

        return DB::transaction(function () use ($assignment, $nextNumber) {
            $attempt = SetAttempt::create([
                'set_assignment_id' => $assignment->id,
                'attempt_number' => $nextNumber,
                'mode' => $assignment->practiceSet->isChapterTest()
                    ? SetAttempt::MODE_BATCH
                    : SetAttempt::MODE_GUIDED,
                'started_at' => now(),
                'active_seconds' => 0,
                'active_session_started_at' => now(),
                'status' => SetAttempt::STATUS_IN_PROGRESS,
            ]);

            if ($attempt->isGuided()) {
                $this->guidedPractice->initialize($attempt);
            }

            if ($assignment->status === SetAssignment::STATUS_ASSIGNED) {
                $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);
            }

            return $attempt;
        });
    }

    public function submit(SetAttempt $attempt, array $answers): SetAttempt
    {
        if ($attempt->status === SetAttempt::STATUS_SUBMITTED) {
            throw new \InvalidArgumentException('This attempt has already been submitted.');
        }

        $assignment = $attempt->assignment()->with('practiceSet.questions.options')->first();
        $questions = $assignment->practiceSet->questions->keyBy('id');

        return DB::transaction(function () use ($attempt, $answers, $assignment, $questions) {
            $score = 0;
            $maxScore = $questions->count();

            foreach ($questions as $question) {
                $selectedOptionId = $answers[$question->id] ?? null;
                $isCorrect = false;

                if ($selectedOptionId) {
                    $option = $question->options->firstWhere('id', (int) $selectedOptionId);
                    $isCorrect = $option?->is_correct ?? false;
                }

                if ($isCorrect) {
                    $score++;
                }

                SetAttemptAnswer::updateOrCreate(
                    [
                        'set_attempt_id' => $attempt->id,
                        'question_id' => $question->id,
                    ],
                    [
                        'question_option_id' => $selectedOptionId ?: null,
                        'is_correct' => $isCorrect,
                    ],
                );
            }

            $timeSeconds = AttemptTiming::finalizeActiveTime($attempt);
            $completedAt = now();
            $submissionTiming = AssignmentProgress::submissionTiming($assignment, $completedAt);

            $attempt->update([
                'completed_at' => $completedAt,
                'score' => $score,
                'max_score' => $maxScore,
                'time_seconds' => $timeSeconds,
                'status' => SetAttempt::STATUS_SUBMITTED,
                'submission_timing' => $submissionTiming,
            ]);

            $assignment->update(['status' => SetAssignment::STATUS_COMPLETED]);

            $freshAttempt = $attempt->fresh(['answers', 'assignment.practiceSet']);

            AssignmentMailer::sendCompleted($freshAttempt);

            return $freshAttempt;
        });
    }

    public function ensureGuidedForTopicPractice(SetAttempt $attempt): void
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || $attempt->isGuided()) {
            return;
        }

        $attempt->loadMissing('assignment.practiceSet');

        if ($attempt->assignment->practiceSet->isChapterTest()) {
            return;
        }

        DB::transaction(function () use ($attempt) {
            $this->guidedPractice->initialize($attempt);
        });
    }

    public function dashboardForEnrollment(StudentEnrollment $enrollment): array
    {
        $assignments = SetAssignment::query()
            ->with([
                'practiceSet' => fn ($q) => $q->withCount('questions'),
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number')->limit(1),
            ])
            ->where('student_enrollment_id', $enrollment->id)
            ->whereHas('practiceSet', fn ($q) => $q->where('status', 'published'))
            ->get();

        return $assignments->map(function (SetAssignment $assignment) {
            $latest = $assignment->attempts->first();

            return AssignmentProgress::formatStudentDashboardSummary($assignment, $latest);
        })->sortBy([
            ['set_code', 'asc'],
            ['set_number', 'asc'],
        ])->values()->all();
    }

    /**
     * @return array{correct: bool, message: string, correct_answer: ?string}
     */
    public function checkPracticeRetry(
        SetAttempt $attempt,
        int $questionId,
        ?int $optionId = null,
        ?string $answerText = null,
    ): array {
        if ($attempt->status !== SetAttempt::STATUS_SUBMITTED) {
            throw new \InvalidArgumentException('Practice retry is only available after submission.');
        }

        $assignment = $attempt->assignment()->with([
            'practiceSet.questions.options',
            'practiceSet.questions.blankAnswer',
        ])->first();

        $question = $assignment->practiceSet->questions->firstWhere('id', $questionId);

        if (! $question) {
            throw new \InvalidArgumentException('Question not found in this set.');
        }

        if ($attempt->isGuided()) {
            $guided = $attempt->guidedQuestions()->where('question_id', $questionId)->first();

            if ($guided?->final_is_correct) {
                throw new \InvalidArgumentException('This question was already answered correctly.');
            }
        } else {
            $answer = $attempt->answers()->where('question_id', $questionId)->first();

            if ($answer?->is_correct) {
                throw new \InvalidArgumentException('This question was already answered correctly.');
            }
        }

        $isCorrect = false;

        if ($question->isFillInBlank()) {
            if (! filled($answerText)) {
                throw new \InvalidArgumentException('Enter an answer before submitting.');
            }

            $isCorrect = $this->answerValidation->isCorrect($question, $answerText);
        } else {
            if (! $optionId) {
                throw new \InvalidArgumentException('Select an option before submitting.');
            }

            $option = $question->options->firstWhere('id', $optionId);
            $isCorrect = $option?->is_correct ?? false;
        }

        return [
            'correct' => $isCorrect,
            'message' => $isCorrect
                ? 'Correct! Well done.'
                : 'Not quite — try again.',
            'correct_answer' => $isCorrect
                ? AttemptResultSummary::correctAnswerForQuestion($question)
                : null,
        ];
    }
}
