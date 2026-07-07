<?php

namespace App\Support;

use App\Models\Question;
use App\Models\QuestionBlankAnswer;

class AnswerValidationService
{
    public function isCorrect(Question $question, string $studentAnswer): bool
    {
        if ($question->isFillInBlank()) {
            $question->loadMissing('blankAnswer');
            $blank = $question->blankAnswer;

            if (! $blank) {
                return false;
            }

            return $this->matchesBlankAnswer($blank, $studentAnswer);
        }

        return false;
    }

    public function matchesBlankAnswer(QuestionBlankAnswer $blank, string $studentAnswer): bool
    {
        $student = $this->normalizeInput($studentAnswer);

        if ($student === null) {
            return false;
        }

        return match ($blank->answer_format) {
            QuestionBlankAnswer::FORMAT_INTEGER => $this->matchesInteger($blank->correct_answer, $student),
            QuestionBlankAnswer::FORMAT_DECIMAL => $this->matchesDecimal($blank->correct_answer, $student, $blank->decimal_places),
            QuestionBlankAnswer::FORMAT_FRACTION => $this->matchesFraction($blank->correct_answer, $student),
            default => false,
        };
    }

    public function formatLabel(?string $format): string
    {
        return match ($format) {
            QuestionBlankAnswer::FORMAT_INTEGER => 'Whole number',
            QuestionBlankAnswer::FORMAT_DECIMAL => 'Decimal',
            QuestionBlankAnswer::FORMAT_FRACTION => 'Fraction',
            default => 'Answer',
        };
    }

    private function matchesInteger(string $expected, string $student): bool
    {
        if (! preg_match('/^-?\d+$/', $student)) {
            return false;
        }

        return (int) $expected === (int) $student;
    }

    private function matchesDecimal(string $expected, string $student, ?int $decimalPlaces): bool
    {
        $expectedValue = $this->parseDecimal($expected);
        $studentValue = $this->parseDecimal($student);

        if ($expectedValue === null || $studentValue === null) {
            return false;
        }

        $places = $decimalPlaces ?? $this->inferDecimalPlaces($expected);
        $scale = 10 ** $places;

        return abs(round($expectedValue * $scale) - round($studentValue * $scale)) <= 1;
    }

    private function matchesFraction(string $expected, string $student): bool
    {
        $expectedRational = $this->parseRational($expected);
        $studentRational = $this->parseRational($student);

        if ($expectedRational === null || $studentRational === null) {
            return false;
        }

        return ($expectedRational[0] * $studentRational[1]) === ($studentRational[0] * $expectedRational[1]);
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function parseRational(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value)) {
            return [(int) $value, 1];
        }

        if (preg_match('/^-?\d+\.\d+$/', $value)) {
            $decimal = $this->parseDecimal($value);

            if ($decimal === null) {
                return null;
            }

            return $this->decimalToRational($decimal);
        }

        if (preg_match('/^(-?\d+)\s+(\d+)\s*\/\s*(\d+)$/', $value, $matches)) {
            $whole = (int) $matches[1];
            $numerator = (int) $matches[2];
            $denominator = (int) $matches[3];

            if ($denominator === 0) {
                return null;
            }

            $sign = $whole < 0 ? -1 : 1;
            $wholeAbs = abs($whole);

            return [$sign * ($wholeAbs * $denominator + $numerator), $denominator];
        }

        if (preg_match('/^(-?\d+)\s*\/\s*(\d+)$/', $value, $matches)) {
            $numerator = (int) $matches[1];
            $denominator = (int) $matches[2];

            if ($denominator === 0) {
                return null;
            }

            return [$numerator, $denominator];
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function decimalToRational(float $value): array
    {
        $sign = $value < 0 ? -1 : 1;
        $value = abs($value);
        $denominator = 1000000;
        $numerator = (int) round($value * $denominator);

        $gcd = $this->gcd($numerator, $denominator);

        return [$sign * intdiv($numerator, $gcd), intdiv($denominator, $gcd)];
    }

    private function gcd(int $a, int $b): int
    {
        $a = abs($a);
        $b = abs($b);

        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, $a);
    }

    private function parseDecimal(string $value): ?float
    {
        $value = trim(str_replace(',', '.', $value));

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function inferDecimalPlaces(string $expected): int
    {
        if (! str_contains($expected, '.')) {
            return 0;
        }

        return strlen(rtrim(substr(strrchr($expected, '.'), 1), '0'));
    }

    private function normalizeInput(string $value): ?string
    {
        $value = trim(str_replace(',', '.', $value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value === '' ? null : $value;
    }
}
