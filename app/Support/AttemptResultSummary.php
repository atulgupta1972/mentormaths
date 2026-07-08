<?php

namespace App\Support;

use App\Models\GuidedAttemptQuestion;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Worksheet;
use App\Support\QuestionMethodHint;

class AttemptResultSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function forStudentReview(SetAttempt $attempt): array
    {
        $attempt->loadMissing([
            'assignment.practiceSet.questions.options',
            'assignment.practiceSet.questions.blankAnswer',
            'assignment.enrollment.student',
        ]);

        $summary = self::build($attempt, includeCorrect: true);

        if ($attempt->isGuided()) {
            $summary['questions'] = self::guidedReviewRows($attempt);
        } else {
            $summary['questions'] = self::batchReviewRows($attempt, $attempt->assignment->practiceSet);
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function guidedReviewRows(SetAttempt $attempt): array
    {
        $attempt->loadMissing([
            'guidedQuestions.question.options',
            'guidedQuestions.question.blankAnswer',
        ]);

        return $attempt->guidedQuestions
            ->sortBy('sort_order')
            ->values()
            ->map(function (GuidedAttemptQuestion $guided, int $index) {
                $question = $guided->question;

                return [
                    'number' => $index + 1,
                    'question_text' => $question?->question_text,
                    'diagram_url' => $question?->diagram_url,
                    'type' => $question?->type,
                    'method_hint' => ($guided->corrected_after_help || ($guided->wrong_before_explanation ?? 0) >= 2)
                        ? QuestionMethodHint::forStudent($question)
                        : null,
                    'correct_answer' => self::correctAnswerLabel($question),
                    'attempts' => self::guidedReviewAttempts($guided, $question),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function guidedReviewAttempts(GuidedAttemptQuestion $guided, $question): array
    {
        $attempts = [];

        if ($guided->first_try_correct) {
            $attempts[] = self::formatReviewAttempt(
                'Correct on first try',
                $guided->final_option_id,
                $guided->final_answer_text,
                $question,
                true,
            );

            return $attempts;
        }

        if ($guided->first_wrong_option_id || filled($guided->first_wrong_answer_text)) {
            $attempts[] = self::formatReviewAttempt(
                '1st try — wrong',
                $guided->first_wrong_option_id,
                $guided->first_wrong_answer_text,
                $question,
                false,
            );
        } elseif (($guided->wrong_before_explanation ?? 0) >= 1) {
            $attempts[] = [
                'label' => '1st try — wrong',
                'answer' => null,
                'is_correct' => false,
            ];
        }

        if ($guided->second_wrong_option_id || filled($guided->second_wrong_answer_text)) {
            $attempts[] = self::formatReviewAttempt(
                '2nd try — wrong',
                $guided->second_wrong_option_id,
                $guided->second_wrong_answer_text,
                $question,
                false,
            );
        } elseif (($guided->wrong_before_explanation ?? 0) >= 2) {
            $attempts[] = [
                'label' => '2nd try — wrong',
                'answer' => null,
                'is_correct' => false,
            ];
        }

        if ($guided->gave_up) {
            $attempts[] = [
                'label' => 'Asked for teacher help',
                'answer' => null,
                'is_correct' => false,
            ];

            return $attempts;
        }

        if ($guided->final_is_correct) {
            $label = $guided->corrected_after_help
                ? 'Correct after method'
                : (($guided->wrong_before_explanation ?? 0) >= 1 ? '2nd try — correct' : 'Correct answer');
            $attempts[] = self::formatReviewAttempt(
                $label,
                $guided->final_option_id,
                $guided->final_answer_text,
                $question,
                true,
            );
        } elseif ($guided->final_option_id || filled($guided->final_answer_text)) {
            $attempts[] = self::formatReviewAttempt(
                'Final try — wrong',
                $guided->final_option_id,
                $guided->final_answer_text,
                $question,
                false,
            );
        }

        return $attempts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function batchReviewRows(SetAttempt $attempt, Worksheet $worksheet): array
    {
        $attempt->loadMissing(['answers.question.options', 'answers.question.blankAnswer']);
        $answersByQuestion = $attempt->answers->keyBy('question_id');
        $rows = [];

        foreach ($worksheet->questions as $index => $question) {
            $answer = $answersByQuestion->get($question->id);
            $attempts = [];

            if ($answer) {
                $attempts[] = self::formatReviewAttempt(
                    $answer->is_correct ? 'Your answer — correct' : 'Your answer — wrong',
                    $answer->question_option_id,
                    $answer->answer_text,
                    $question,
                    (bool) $answer->is_correct,
                );
            }

            $rows[] = [
                'number' => $index + 1,
                'question_text' => $question->question_text,
                'diagram_url' => $question->diagram_url,
                'type' => $question->type,
                'method_hint' => null,
                'correct_answer' => self::correctAnswerLabel($question),
                'attempts' => $attempts,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function formatReviewAttempt(
        string $label,
        ?int $optionId,
        ?string $answerText,
        $question,
        bool $isCorrect,
    ): array {
        $answer = null;

        if ($optionId && $question?->relationLoaded('options')) {
            $option = $question->options->firstWhere('id', $optionId);

            if ($option) {
                $index = $question->options->values()->search(fn ($row) => $row->id === $option->id);
                $letter = $index !== false ? chr(65 + $index) : null;
                $answer = trim(($letter ? "{$letter}. " : '').$option->option_text);
            }
        }

        if ($answer === null && filled($answerText)) {
            $answer = $answerText;
        }

        return [
            'label' => $label,
            'answer' => $answer,
            'is_correct' => $isCorrect,
        ];
    }

    private static function correctAnswerLabel($question): ?string
    {
        if (! $question) {
            return null;
        }

        if ($question->isFillInBlank()) {
            $question->loadMissing('blankAnswer');

            return $question->blankAnswer?->correct_answer;
        }

        $question->loadMissing('options');
        $correct = $question->options->firstWhere('is_correct', true);

        if (! $correct) {
            return null;
        }

        $index = $question->options->values()->search(fn ($row) => $row->id === $correct->id);
        $letter = $index !== false ? chr(65 + $index) : null;

        return trim(($letter ? "{$letter}. " : '').$correct->option_text);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forMail(SetAttempt $attempt): array
    {
        return self::build($attempt, includeCorrect: false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(SetAttempt $attempt): array
    {
        return self::build($attempt, includeCorrect: true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function build(SetAttempt $attempt, bool $includeCorrect): array
    {
        $attempt->loadMissing([
            'answers.question.topic.chapter',
            'guidedQuestions.question.topic.chapter',
            'assignment.enrollment.student',
            'assignment.practiceSet.topic.chapter',
            'assignment.practiceSet.chapter',
            'assignment.practiceSet.questions.topic.chapter',
        ]);

        $assignment = $attempt->assignment;
        $worksheet = $assignment->practiceSet;
        $student = $assignment->enrollment->student;
        $scope = self::scopeLines($worksheet);

        $questionRows = $attempt->isGuided()
            ? self::guidedQuestionRows($attempt, $includeCorrect)
            : self::batchQuestionRows($attempt, $worksheet, $includeCorrect);

        $helpAskedCount = $attempt->isGuided()
            ? $attempt->guidedQuestions->filter(
                fn ($guided) => ($guided->wrong_before_explanation ?? 0) > 0 || $guided->gave_up,
            )->count()
            : 0;

        return [
            'student_name' => $student->name,
            'set_code' => $worksheet->set_code,
            'kind_label' => $worksheet->isChapterScope() ? 'Test' : 'Practice',
            'tier_label' => $worksheet->tier_label,
            'display_title' => $worksheet->display_title,
            'chapter_name' => $scope['chapter_name'],
            'topic_name' => $scope['topic_name'],
            'scope_line' => $scope['scope_line'],
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'score_label' => "{$attempt->score}/{$attempt->max_score}",
            'time_seconds' => $attempt->time_seconds,
            'time_label' => self::formatTimeLabel($attempt->time_seconds),
            'attempt_number' => $attempt->attempt_number,
            'attempt_label' => 'Attempt '.$attempt->attempt_number,
            'attempt_history' => self::attemptHistory($assignment, $attempt),
            'completed_at' => $attempt->completed_at?->toDateTimeString(),
            'completed_label' => DateLabels::formatDateTime($attempt->completed_at),
            'target_date' => $assignment->due_date?->toDateString(),
            'target_label' => DateLabels::formatDate($assignment->due_date?->toDateString()),
            'submission_timing' => $attempt->submission_timing,
            'submission_timing_label' => $attempt->submission_timing === SetAttempt::TIMING_LATE
                ? 'Delayed submission'
                : 'On time',
            'is_guided' => $attempt->isGuided(),
            'first_try_correct' => $attempt->first_try_correct_count,
            'corrected_after_help' => $attempt->corrected_after_help_count,
            'given_up' => $attempt->given_up_count,
            'help_asked_count' => $helpAskedCount,
            'assignment_id' => $assignment->id,
            'admin_url' => route('admin.set-assignments.show', $assignment),
            'questions' => $questionRows,
            'wrong_questions' => array_values(array_filter(
                $questionRows,
                fn (array $row) => $row['outcome'] !== 'correct',
            )),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function attemptHistory(SetAssignment $assignment, SetAttempt $current): array
    {
        $assignment->loadMissing([
            'attempts' => fn ($query) => $query->orderBy('attempt_number'),
        ]);

        return $assignment->attempts->map(function (SetAttempt $attempt) use ($current) {
            $submitted = $attempt->status === SetAttempt::STATUS_SUBMITTED;

            return [
                'attempt_number' => $attempt->attempt_number,
                'is_current' => $attempt->id === $current->id,
                'status' => $attempt->status,
                'score_label' => $submitted ? "{$attempt->score}/{$attempt->max_score}" : 'In progress',
                'time_label' => self::formatTimeLabel($attempt->time_seconds),
                'completed_label' => DateLabels::formatDateTime($attempt->completed_at),
                'submission_timing_label' => $submitted
                    ? ($attempt->submission_timing === SetAttempt::TIMING_LATE ? 'Delayed' : 'On time')
                    : '—',
                'first_try_correct' => $attempt->first_try_correct_count,
                'corrected_after_help' => $attempt->corrected_after_help_count,
                'given_up' => $attempt->given_up_count,
                'is_guided' => $attempt->isGuided(),
            ];
        })->values()->all();
    }

    /**
     * @return array{chapter_name: ?string, topic_name: ?string, scope_line: ?string}
     */
    private static function scopeLines(Worksheet $worksheet): array
    {
        if ($worksheet->isChapterScope()) {
            $chapterName = $worksheet->chapter?->name;

            return [
                'chapter_name' => $chapterName,
                'topic_name' => null,
                'scope_line' => $chapterName ? "Chapter: {$chapterName}" : null,
            ];
        }

        $topicName = $worksheet->topic?->name;
        $chapterName = $worksheet->topic?->chapter?->name;

        if ($topicName && $chapterName) {
            return [
                'chapter_name' => $chapterName,
                'topic_name' => $topicName,
                'scope_line' => "Topic: {$topicName} ({$chapterName})",
            ];
        }

        if ($topicName) {
            return [
                'chapter_name' => $chapterName,
                'topic_name' => $topicName,
                'scope_line' => "Topic: {$topicName}",
            ];
        }

        return [
            'chapter_name' => $chapterName,
            'topic_name' => $topicName,
            'scope_line' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function batchQuestionRows(SetAttempt $attempt, Worksheet $worksheet, bool $includeCorrect): array
    {
        $answersByQuestion = $attempt->answers->keyBy('question_id');
        $rows = [];

        foreach ($worksheet->questions as $index => $question) {
            $answer = $answersByQuestion->get($question->id);
            $isCorrect = $answer?->is_correct ?? false;
            $outcome = $isCorrect ? 'correct' : 'incorrect';

            if (! $includeCorrect && $isCorrect) {
                continue;
            }

            $rows[] = [
                'number' => $index + 1,
                'topic_name' => $question->topic?->name,
                'chapter_name' => $question->topic?->chapter?->name,
                'outcome' => $outcome,
                'outcome_label' => $isCorrect ? 'Correct' : 'Wrong answer',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function guidedQuestionRows(SetAttempt $attempt, bool $includeCorrect): array
    {
        $rows = [];

        foreach ($attempt->guidedQuestions as $index => $guided) {
            $question = $guided->question;

            if ($guided->first_try_correct) {
                $outcome = 'correct';
                $outcomeLabel = 'Correct on first try';
            } elseif ($guided->gave_up) {
                $outcome = 'gave_up';
                $outcomeLabel = 'Gave up — needs teacher help';
            } elseif ($guided->corrected_after_help) {
                $outcome = 'corrected_after_help';
                $outcomeLabel = 'Correct after using method';
            } else {
                $outcome = 'incorrect';
                $outcomeLabel = 'Not correct on first try';
            }

            if (! $includeCorrect && $outcome === 'correct') {
                continue;
            }

            $helpAsked = ($guided->wrong_before_explanation ?? 0) > 0;

            $rows[] = [
                'number' => $index + 1,
                'topic_name' => $question?->topic?->name,
                'chapter_name' => $question?->topic?->chapter?->name,
                'outcome' => $outcome,
                'outcome_label' => $outcomeLabel,
                'help_asked' => $helpAsked || $guided->gave_up,
                'help_asked_label' => self::guidedHelpLabel($guided),
                'wrong_before_help' => (int) ($guided->wrong_before_explanation ?? 0),
            ];
        }

        return $rows;
    }

    private static function guidedHelpLabel(GuidedAttemptQuestion $guided): ?string
    {
        if ($guided->gave_up) {
            return 'Asked for teacher help (gave up)';
        }

        if (($guided->wrong_before_explanation ?? 0) > 0) {
            $attempts = (int) $guided->wrong_before_explanation;

            return $attempts === 1
                ? 'Used method help after 1 wrong try'
                : "Used method help after {$attempts} wrong tries";
        }

        if ($guided->first_try_correct) {
            return 'No help needed';
        }

        return null;
    }

    public static function formatTimeLabel(?int $seconds): string
    {
        if (! $seconds) {
            return '—';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}m {$remaining}s";
        }

        return "{$remaining}s";
    }
}
