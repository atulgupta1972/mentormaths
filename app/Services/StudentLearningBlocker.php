<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentSeriesActivityDay;
use App\Models\User;
use App\Support\FormulaDrillSchema;
use Illuminate\Support\Facades\Schema;

/**
 * First step that stops a student from opening concepts or tests.
 * Order matches the student route gates.
 */
class StudentLearningBlocker
{
    public const PROMPT_FLASH = 'learning_blocker_prompt';

    public function __construct(
        private FirstTermMarksGate $marks,
        private ChapterTermMapGate $terms,
        private FormulaDrillSessionService $formula,
        private SeriesActivityService $series,
        private BasicsDrillSessionService $basics,
    ) {}

    /**
     * @return array{
     *     key: string,
     *     title: string,
     *     message: string,
     *     action_label: string,
     *     action_url: string,
     *     blocks_assign: bool
     * }|null
     */
    public function forUser(?User $user): ?array
    {
        if (! $user || $user->isAdmin() || ! $user->isStudent()) {
            return null;
        }

        $student = $user->student;
        if (! $student) {
            return null;
        }

        if ($this->marks->isRequired() && ! $this->marks->gatePassed($student)) {
            return $this->make(
                'first_term_marks',
                'Enter your Half yearly marks',
                $this->marks->blockingMessage($student),
                'Enter marks',
                route('student.exams.index'),
                true,
            );
        }

        if ($this->terms->isRequired() && ! $this->terms->gatePassed($student)) {
            $status = $this->terms->statusForEnrollment($student->currentEnrollment());

            return $this->make(
                'chapter_terms',
                'Tag Term 1 and Term 2',
                $status['message'] ?? $this->terms->blockingMessage($status),
                'Tag chapters',
                route('student.school-study-plan.show'),
                true,
            );
        }

        if (! FormulaDrillSchema::isReady() || $this->formula->gatePassed($student)) {
            return $this->afterFormula($student);
        }

        return $this->make(
            'formula_drill',
            'Finish today\'s formula drill',
            'Do today\'s formula drill first. Concepts and tests open after that.',
            'Do formula drill',
            route('student.formula-drill.show'),
            false,
        );
    }

    /**
     * @return array{
     *     key: string,
     *     title: string,
     *     message: string,
     *     action_label: string,
     *     action_url: string,
     *     blocks_assign: bool
     * }|null
     */
    private function afterFormula(Student $student): ?array
    {
        if (! FormulaDrillSchema::isReady() || ! $this->formula->gatePassed($student)) {
            return null;
        }

        $seriesTables = Schema::hasTable('mensuration_match_sessions')
            || Schema::hasTable('student_series_activity_days');

        if ($seriesTables && ! $this->series->gatePassed($student)) {
            if ($this->series->todaysKind($student) === StudentSeriesActivityDay::KIND_ALGEBRA) {
                return $this->make(
                    'algebra_cards',
                    'Finish today\'s algebra cards',
                    'Do today\'s algebra cards first. Concepts and tests open after that.',
                    'Do algebra cards',
                    route('student.algebra-cards.show'),
                    false,
                );
            }

            return $this->make(
                'mensuration_match',
                'Finish today\'s mensuration match',
                'Do today\'s mensuration match first. Concepts and tests open after that.',
                'Do mensuration match',
                route('student.mensuration-match.show'),
                false,
            );
        }

        if (! Schema::hasTable('basics_drill_sessions') || ! $this->series->gatePassed($student)) {
            return null;
        }

        if ($this->basics->gatePassed($student)) {
            return null;
        }

        return $this->make(
            'basics_drill',
            'Finish today\'s basics drill',
            'Do today\'s basics drill first. Concepts and tests open after that.',
            'Do basics drill',
            route('student.basics-drill.show'),
            false,
        );
    }

    /**
     * @return array{
     *     key: string,
     *     title: string,
     *     message: string,
     *     action_label: string,
     *     action_url: string,
     *     blocks_assign: bool
     * }
     */
    private function make(
        string $key,
        string $title,
        string $message,
        string $actionLabel,
        string $actionUrl,
        bool $blocksAssign,
    ): array {
        return [
            'key' => $key,
            'title' => $title,
            'message' => $message,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'blocks_assign' => $blocksAssign,
        ];
    }
}
