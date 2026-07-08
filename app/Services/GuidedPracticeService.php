<?php

namespace App\Services;

use App\Models\GuidedAttemptQuestion;
use App\Models\QuestionResolutionItem;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use App\Models\SetAssignment;
use App\Support\AnswerValidationService;
use App\Support\AssignmentMailer;
use App\Support\AssignmentProgress;
use App\Support\AttemptTiming;
use App\Support\QuestionMethodHint;
use Illuminate\Support\Facades\DB;

class GuidedPracticeService
{
    public function __construct(
        private AnswerValidationService $answerValidation,
    ) {}
    public function initialize(SetAttempt $attempt): void
    {
        if ($attempt->guidedQuestions()->exists()) {
            if (! $attempt->isGuided()) {
                $attempt->update(['mode' => SetAttempt::MODE_GUIDED]);
            }

            return;
        }

        $assignment = $attempt->assignment()->with('practiceSet.questions')->first();
        $questions = $assignment->practiceSet->questions->values();

        foreach ($questions as $index => $question) {
            GuidedAttemptQuestion::create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'sort_order' => $index,
                'phase' => $index === 0
                    ? GuidedAttemptQuestion::PHASE_ANSWERING
                    : GuidedAttemptQuestion::PHASE_PENDING,
            ]);
        }

        $attempt->update([
            'mode' => SetAttempt::MODE_GUIDED,
            'current_question_index' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(SetAttempt $attempt): array
    {
        $attempt->loadMissing([
            'assignment.practiceSet',
            'guidedQuestions.question.options',
            'guidedQuestions.question.blankAnswer',
        ]);

        $guidedRows = $attempt->guidedQuestions;
        $current = $guidedRows->firstWhere('sort_order', $attempt->current_question_index);

        if (! $current) {
            return [
                'finished' => true,
                'summary' => $this->summary($attempt),
            ];
        }

        $question = $current->question;

        return [
            'finished' => false,
            'progress' => [
                'current' => $attempt->current_question_index + 1,
                'total' => $guidedRows->count(),
            ],
            'phase' => $current->phase,
            'show_explanation' => $current->phase === GuidedAttemptQuestion::PHASE_EXPLAINED,
            'can_give_up' => in_array($current->phase, [
                GuidedAttemptQuestion::PHASE_ANSWERING,
                GuidedAttemptQuestion::PHASE_RETRY,
                GuidedAttemptQuestion::PHASE_EXPLAINED,
            ], true),
            'feedback' => null,
            'question' => $this->formatQuestion($question, $attempt->current_question_index + 1, $current),
            'practice_set' => [
                'set_code' => $attempt->assignment->practiceSet->set_code,
                'set_number' => $attempt->assignment->practiceSet->set_number,
                'kind_label' => 'Practice',
            ],
            'attempt' => [
                'id' => $attempt->id,
                'started_at' => $attempt->started_at->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function submitAnswer(SetAttempt $attempt, ?int $optionId = null, ?string $answerText = null): array
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || ! $attempt->isGuided()) {
            throw new \InvalidArgumentException('This guided practice session is not active.');
        }

        $attempt->loadMissing([
            'guidedQuestions.question.options',
            'guidedQuestions.question.blankAnswer',
        ]);
        $current = $attempt->guidedQuestions->firstWhere('sort_order', $attempt->current_question_index);

        if (! $current || $current->isFinished()) {
            throw new \InvalidArgumentException('No active question in this practice session.');
        }

        if (! in_array($current->phase, [
            GuidedAttemptQuestion::PHASE_ANSWERING,
            GuidedAttemptQuestion::PHASE_RETRY,
            GuidedAttemptQuestion::PHASE_EXPLAINED,
        ], true)) {
            throw new \InvalidArgumentException('You cannot answer this question in its current state.');
        }

        $question = $current->question;
        $isCorrect = false;
        $resolvedOptionId = null;
        $resolvedAnswerText = null;

        if ($question->isFillInBlank()) {
            if (! filled($answerText)) {
                throw new \InvalidArgumentException('Enter an answer before submitting.');
            }

            $resolvedAnswerText = trim($answerText);
            $isCorrect = $this->answerValidation->isCorrect($question, $resolvedAnswerText);
        } else {
            if (! $optionId) {
                throw new \InvalidArgumentException('Select an option before submitting.');
            }

            $option = $question->options->firstWhere('id', $optionId);

            if (! $option) {
                throw new \InvalidArgumentException('Invalid option selected.');
            }

            $resolvedOptionId = $optionId;
            $isCorrect = (bool) $option->is_correct;
        }

        return DB::transaction(function () use ($attempt, $current, $resolvedOptionId, $resolvedAnswerText, $isCorrect) {
            $feedback = match ($current->phase) {
                GuidedAttemptQuestion::PHASE_ANSWERING => $this->handleFirstTry($current, $resolvedOptionId, $resolvedAnswerText, $isCorrect),
                GuidedAttemptQuestion::PHASE_RETRY => $this->handleSecondTry($current, $resolvedOptionId, $resolvedAnswerText, $isCorrect),
                GuidedAttemptQuestion::PHASE_EXPLAINED => $this->handleAfterExplanation($current, $resolvedOptionId, $resolvedAnswerText, $isCorrect),
                default => throw new \InvalidArgumentException('Unexpected question phase.'),
            };

            if ($current->fresh()->isFinished()) {
                $this->advanceOrFinalize($attempt);
            }

            $payload = $this->buildPayload($attempt->fresh([
                'assignment.practiceSet',
                'guidedQuestions.question.options',
                'guidedQuestions.question.blankAnswer',
            ]));
            $payload['feedback'] = $feedback;

            return $payload;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function giveUp(SetAttempt $attempt): array
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || ! $attempt->isGuided()) {
            throw new \InvalidArgumentException('This guided practice session is not active.');
        }

        $attempt->loadMissing(['guidedQuestions', 'assignment']);
        $current = $attempt->guidedQuestions->firstWhere('sort_order', $attempt->current_question_index);

        if (! $current || ! in_array($current->phase, [
            GuidedAttemptQuestion::PHASE_ANSWERING,
            GuidedAttemptQuestion::PHASE_RETRY,
            GuidedAttemptQuestion::PHASE_EXPLAINED,
        ], true)) {
            throw new \InvalidArgumentException('You cannot ask for help on this question right now.');
        }

        return DB::transaction(function () use ($attempt, $current) {
            $current->update([
                'phase' => GuidedAttemptQuestion::PHASE_GIVEN_UP,
                'gave_up' => true,
                'final_is_correct' => false,
            ]);

            $this->queueForResolution($attempt, $current);
            $this->advanceOrFinalize($attempt);

            $payload = $this->buildPayload($attempt->fresh([
                'assignment.practiceSet',
                'guidedQuestions.question.options',
                'guidedQuestions.question.blankAnswer',
            ]));
            $payload['help_requested'] = true;

            return $payload;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function handleFirstTry(GuidedAttemptQuestion $current, ?int $optionId, ?string $answerText, bool $isCorrect): array
    {
        if ($isCorrect) {
            $current->update([
                'phase' => GuidedAttemptQuestion::PHASE_DONE,
                'first_try_correct' => true,
                'final_option_id' => $optionId,
                'final_answer_text' => $answerText,
                'final_is_correct' => true,
            ]);

            return [
                'type' => 'correct',
                'message' => 'Correct on the first try. Well done.',
            ];
        }

        $current->update([
            'phase' => GuidedAttemptQuestion::PHASE_RETRY,
            'wrong_before_explanation' => 1,
        ]);

        return [
            'type' => 'retry',
            'message' => 'Not quite. Try once more before the method is shown.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleSecondTry(GuidedAttemptQuestion $current, ?int $optionId, ?string $answerText, bool $isCorrect): array
    {
        if ($isCorrect) {
            $current->update([
                'phase' => GuidedAttemptQuestion::PHASE_DONE,
                'final_option_id' => $optionId,
                'final_answer_text' => $answerText,
                'final_is_correct' => true,
            ]);

            return [
                'type' => 'correct',
                'message' => 'Correct. Good recovery.',
            ];
        }

        $current->update([
            'phase' => GuidedAttemptQuestion::PHASE_EXPLAINED,
            'wrong_before_explanation' => 2,
        ]);

        return [
            'type' => 'explained',
            'message' => 'Read the method below, then try again or tap I need help if you want your teacher to explain.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleAfterExplanation(GuidedAttemptQuestion $current, ?int $optionId, ?string $answerText, bool $isCorrect): array
    {
        if ($isCorrect) {
            $current->update([
                'phase' => GuidedAttemptQuestion::PHASE_DONE,
                'corrected_after_help' => true,
                'final_option_id' => $optionId,
                'final_answer_text' => $answerText,
                'final_is_correct' => true,
            ]);

            return [
                'type' => 'correct',
                'message' => 'Correct after using the method. This does not count in your first-try score.',
            ];
        }

        return [
            'type' => 'incorrect',
            'message' => 'Still not correct. Read the method again, try once more, or tap I need help for your teacher.',
        ];
    }

    private function advanceOrFinalize(SetAttempt $attempt): void
    {
        $attempt->loadMissing('guidedQuestions');
        $nextIndex = $attempt->current_question_index + 1;
        $next = $attempt->guidedQuestions->firstWhere('sort_order', $nextIndex);

        if ($next) {
            $next->update(['phase' => GuidedAttemptQuestion::PHASE_ANSWERING]);
            $attempt->update(['current_question_index' => $nextIndex]);

            return;
        }

        $this->finalize($attempt);
    }

    private function finalize(SetAttempt $attempt): void
    {
        $attempt->loadMissing(['guidedQuestions', 'assignment']);

        $rows = $attempt->guidedQuestions;
        $firstTryCorrect = $rows->where('first_try_correct', true)->count();
        $correctedAfterHelp = $rows->where('corrected_after_help', true)->count();
        $givenUp = $rows->where('gave_up', true)->count();
        $maxScore = $rows->count();

        foreach ($rows as $row) {
            SetAttemptAnswer::updateOrCreate(
                [
                    'set_attempt_id' => $attempt->id,
                    'question_id' => $row->question_id,
                ],
                [
                    'question_option_id' => $row->final_option_id,
                    'answer_text' => $row->final_answer_text,
                    'is_correct' => $row->final_is_correct,
                ],
            );
        }

        $completedAt = now();
        $assignment = $attempt->assignment;

        $attempt->update([
            'completed_at' => $completedAt,
            'score' => $firstTryCorrect,
            'max_score' => $maxScore,
            'first_try_correct_count' => $firstTryCorrect,
            'corrected_after_help_count' => $correctedAfterHelp,
            'given_up_count' => $givenUp,
            'time_seconds' => AttemptTiming::elapsedSeconds($attempt->started_at, $completedAt),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => AssignmentProgress::submissionTiming($assignment, $completedAt),
        ]);

        $assignment->update(['status' => SetAssignment::STATUS_COMPLETED]);

        AssignmentMailer::sendCompleted($attempt->fresh([
            'guidedQuestions.question.topic.chapter',
            'answers.question.topic.chapter',
            'assignment.practiceSet.topic.chapter',
            'assignment.practiceSet.chapter',
            'assignment.practiceSet.questions.topic.chapter',
        ]));
    }

    private function queueForResolution(SetAttempt $attempt, GuidedAttemptQuestion $guided): void
    {
        $assignment = $attempt->assignment;

        $existing = QuestionResolutionItem::query()
            ->where('student_enrollment_id', $assignment->student_enrollment_id)
            ->where('question_id', $guided->question_id)
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->first();

        if ($existing) {
            $existing->update([
                'set_assignment_id' => $assignment->id,
                'set_attempt_id' => $attempt->id,
                'guided_attempt_question_id' => $guided->id,
                'gave_up_at' => now(),
            ]);

            return;
        }

        QuestionResolutionItem::create([
            'student_enrollment_id' => $assignment->student_enrollment_id,
            'question_id' => $guided->question_id,
            'set_assignment_id' => $assignment->id,
            'set_attempt_id' => $attempt->id,
            'guided_attempt_question_id' => $guided->id,
            'status' => QuestionResolutionItem::STATUS_PENDING,
            'gave_up_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatQuestion($question, int $number, GuidedAttemptQuestion $guided): array
    {
        $payload = [
            'id' => $question->id,
            'type' => $question->type,
            'number' => $number,
            'question_text' => $question->question_text,
            'diagram_url' => $question->diagram_url,
            'method_hint' => $guided->phase === GuidedAttemptQuestion::PHASE_EXPLAINED
                ? QuestionMethodHint::forStudent($question)
                : null,
        ];

        if ($question->isFillInBlank()) {
            $question->loadMissing('blankAnswer');
            $payload['answer_format'] = $question->blankAnswer?->answer_format;
            $payload['answer_format_label'] = $this->answerValidation->formatLabel($question->blankAnswer?->answer_format);
            $payload['options'] = [];

            return $payload;
        }

        $payload['options'] = $question->options->values()->map(function ($option, $index) {
            return [
                'id' => $option->id,
                'letter' => chr(65 + $index),
                'option_text' => $option->option_text,
            ];
        });

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(SetAttempt $attempt): array
    {
        $attempt->loadMissing('guidedQuestions');

        return [
            'first_try_correct' => $attempt->first_try_correct_count ?? 0,
            'max_score' => $attempt->max_score ?? $attempt->guidedQuestions->count(),
            'corrected_after_help' => $attempt->corrected_after_help_count ?? 0,
            'given_up' => $attempt->given_up_count ?? 0,
            'time_seconds' => $attempt->time_seconds,
        ];
    }
}
