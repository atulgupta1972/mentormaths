<?php

namespace App\Support;

use App\Models\Question;

class QuestionMethodHint
{
    /**
     * Theory-only hint for students during guided practice. Never reveals option letters or final numeric answers.
     */
    public static function forStudent(Question $question): ?string
    {
        if (filled($question->method_hint)) {
            return trim((string) $question->method_hint);
        }

        return self::inferFromQuestionText((string) $question->question_text);
    }

    public static function inferFromQuestionText(string $questionText): ?string
    {
        $text = trim($questionText);

        if ($text === '') {
            return null;
        }

        if (self::looksLikeIntegerMultiplication($text)) {
            $negativeCount = self::countNegativeFactors($text);

            if ($negativeCount > 0) {
                $parity = $negativeCount % 2 === 1 ? 'odd' : 'even';
                $signWord = $negativeCount % 2 === 1 ? 'negative' : 'positive';

                return 'When multiplying integers: negative × negative gives a positive. '
                    ."You have {$negativeCount} negative factor".($negativeCount === 1 ? '' : 's')." ({$parity} count), "
                    ."so the final product should be {$signWord}. "
                    .'Multiply step by step — do not guess the last number.';
            }

            return 'Multiply the numbers step by step. Check signs carefully when negative numbers are involved.';
        }

        if (preg_match('/[÷\/]/u', $text) && preg_match('/-\d|\(\s*-/', $text)) {
            return 'When dividing integers: a negative ÷ negative gives a positive; negative ÷ positive (or the reverse) gives a negative. Work in clear steps.';
        }

        if (preg_match('/[+\-−]/u', $text) && preg_match('/-\d|\(\s*-/', $text) && ! preg_match('/[×x*÷\/]/u', $text)) {
            return 'For adding or subtracting integers, combine signs carefully: e.g. subtracting a negative is the same as adding a positive.';
        }

        return null;
    }

    /**
     * Strip answer-key leaks from admin explanation text (for storage cleanup, not student display).
     */
    public static function sanitizeExplanation(?string $explanation): ?string
    {
        if (! filled($explanation)) {
            return null;
        }

        $text = trim((string) $explanation);
        $text = preg_replace('/\s*Answer\s*key\s*:\s*[a-dA-D]\.?/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*Correct\s*(?:answer|option)\s*:\s*[a-dA-D]\.?/iu', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B.,;");

        return $text !== '' ? $text : null;
    }

    private static function looksLikeIntegerMultiplication(string $text): bool
    {
        return (bool) preg_match('/[×x*]/u', $text);
    }

    private static function countNegativeFactors(string $text): int
    {
        preg_match_all('/\(\s*-\d+|\-\d+/u', $text, $matches);

        return count($matches[0]);
    }
}
