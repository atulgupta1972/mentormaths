<?php

namespace App\Services;

use App\Models\QuestionBlankAnswer;

class TextbookChapterAnswerClassificationService
{
    public function looksLikeWordAnswer(?string $answer): bool
    {
        $value = trim((string) $answer);

        if ($value === '') {
            return false;
        }

        if ($this->isMixedFraction($value)) {
            return true;
        }

        $compact = str_replace(',', '', $value);

        if (preg_match('/^-?\d+(?:[.,]\d+)?(?:\s*\/\s*\d+(?:[.,]\d+)?)?$/', $compact)) {
            return false;
        }

        if (preg_match('/^-?\d+\s+\d+\s*\/\s*\d+$/', $value)) {
            return false;
        }

        return (bool) preg_match('/[a-zA-Z]/', $value);
    }

    public function isMixedFraction(?string $answer): bool
    {
        return (bool) preg_match('/^-?\d+\s+\d+\s*\/\s*\d+$/', trim((string) $answer));
    }

    public function isTrueFalseAnswer(?string $answer): bool
    {
        $value = strtolower(trim((string) $answer));

        return in_array($value, ['true', 'false', 'yes', 'no', 't', 'f'], true);
    }

    public function shouldBeFillBlank(?string $answer): bool
    {
        $value = trim((string) $answer);

        if ($value === '') {
            return false;
        }

        if ($this->isTrueFalseAnswer($value) || $this->isMixedFraction($value) || $this->looksLikeWordAnswer($value)) {
            return false;
        }

        return $this->detectAnswerFormat($value) !== null;
    }

    public function detectAnswerFormat(string $answer): ?string
    {
        $compact = str_replace(' ', '', $answer);

        if (preg_match('/^-?\d+\/\d+$/', $compact)) {
            return QuestionBlankAnswer::FORMAT_FRACTION;
        }

        $normalized = str_replace(',', '', $answer);

        if (preg_match('/^-?\d+\.\d+$/', $normalized)) {
            return QuestionBlankAnswer::FORMAT_DECIMAL;
        }

        if (preg_match('/^-?[\d,]+$/', $answer) && ! str_contains($answer, '+')) {
            return QuestionBlankAnswer::FORMAT_INTEGER;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function applyMixedClassification(array $item): array
    {
        $answer = trim((string) ($item['correct_answer'] ?? ''));

        if (! $this->shouldBeFillBlank($answer)) {
            $item['question_type'] = 'mcq';
            $item['include_in_mcq'] = true;
            $item['include_in_written'] = false;
            $item['include_in_fill_blank'] = false;

            return $item;
        }

        $prefill = $this->fillBlankPrefill($item);

        $item['question_type'] = 'fill_blank';
        $item['include_in_mcq'] = false;
        $item['include_in_written'] = false;
        $item['include_in_fill_blank'] = true;
        $item['fill_blank_question_text'] = $prefill['question_text'];
        $item['fill_blank_correct_answer'] = $prefill['correct_answer'];
        $item['fill_blank_answer_format'] = $prefill['answer_format'];
        $item['fill_blank_decimal_places'] = $prefill['decimal_places'];
        $item['fill_blank_explanation'] = $item['explanation'] ?? null;
        $item['fill_blank_method_hint'] = $item['method_hint'] ?? null;
        $item['fill_blank_skipped'] = false;

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{question_text: string, correct_answer: string, answer_format: string, decimal_places: int|null}
     */
    private function fillBlankPrefill(array $item): array
    {
        $mcqAnswer = trim((string) ($item['correct_answer'] ?? ''));
        $stem = trim((string) ($item['question_text'] ?? ''));
        $format = QuestionBlankAnswer::FORMAT_TEXT;
        $places = null;

        if (preg_match('/^-?\d+\/\d+$/', str_replace(' ', '', $mcqAnswer))) {
            $format = QuestionBlankAnswer::FORMAT_FRACTION;
        } elseif (preg_match('/^-?\d+\.\d+$/', str_replace(',', '', $mcqAnswer))) {
            $format = QuestionBlankAnswer::FORMAT_DECIMAL;
            $places = strlen(substr(strrchr(str_replace(',', '', $mcqAnswer), '.') ?: '', 1));
        } elseif (preg_match('/^-?[\d,]+$/', $mcqAnswer) && ! str_contains($mcqAnswer, '+')) {
            $format = QuestionBlankAnswer::FORMAT_INTEGER;
            $mcqAnswer = str_replace(',', '', $mcqAnswer);
        }

        if ($stem !== '' && ! str_contains($stem, '____')) {
            $stem = rtrim($stem, " \t\n\r\0\x0B.?").' The answer is ____.';
        }

        return [
            'question_text' => $stem,
            'correct_answer' => $mcqAnswer,
            'answer_format' => $format,
            'decimal_places' => $places,
        ];
    }
}
