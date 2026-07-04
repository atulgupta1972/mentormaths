<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SyllabusTopic;
use App\Support\QuestionMethodHint;

class QuestionMethodHintService
{
    /**
     * @return array{total: int, updated: int, skipped: int, unresolved: int, explanations_cleaned: int}
     */
    public function fillForTopic(SyllabusTopic $topic, bool $overwrite = false, bool $sanitizeExplanations = true): array
    {
        $questions = Question::query()
            ->where('syllabus_topic_id', $topic->id)
            ->orderBy('id')
            ->get();

        $updated = 0;
        $skipped = 0;
        $unresolved = 0;
        $explanationsCleaned = 0;

        foreach ($questions as $question) {
            $inferred = QuestionMethodHint::inferFromQuestionText((string) $question->question_text);
            $hasStoredHint = filled($question->method_hint);

            if ($hasStoredHint && ! $overwrite) {
                $skipped++;
            } elseif ($inferred !== null) {
                $question->method_hint = $inferred;
                $updated++;
            } elseif ($hasStoredHint) {
                $skipped++;
            } else {
                $unresolved++;
            }

            if ($sanitizeExplanations && filled($question->explanation)) {
                $clean = QuestionMethodHint::sanitizeExplanation($question->explanation);
                if ($clean !== $question->explanation) {
                    $question->explanation = $clean;
                    $explanationsCleaned++;
                }
            }

            if ($question->isDirty()) {
                $question->save();
            }
        }

        return [
            'total' => $questions->count(),
            'updated' => $updated,
            'skipped' => $skipped,
            'unresolved' => $unresolved,
            'explanations_cleaned' => $explanationsCleaned,
        ];
    }

    /**
     * @return array{total: int, with_hint: int, missing_hint: int}
     */
    public function statsForTopic(SyllabusTopic $topic): array
    {
        $total = Question::query()->where('syllabus_topic_id', $topic->id)->count();
        $withHint = Question::query()
            ->where('syllabus_topic_id', $topic->id)
            ->whereNotNull('method_hint')
            ->where('method_hint', '!=', '')
            ->count();

        return [
            'total' => $total,
            'with_hint' => $withHint,
            'missing_hint' => max(0, $total - $withHint),
        ];
    }
}
