<?php

namespace App\Support;

use App\Models\QuestionBlankAnswer;

class FillBlankAnswerConsistency
{
    /** Captures integers, decimals, simple fractions (11/3), and mixed fractions (1 2/3). */
    private const NUMBER_PATTERN = '-?\d+(?:\.\d+)?(?:\s+\d+\s*\/\s*\d+|\s*\/\s*\d+)?';

    /**
     * @return array{
     *     stored_answer: string,
     *     suggested_answer: string,
     *     message: string
     * }|null
     */
    public function mismatch(?string $storedAnswer, ?string $explanation, ?string $format): ?array
    {
        $stored = trim((string) $storedAnswer);
        $explanation = trim((string) $explanation);

        if ($stored === '' || $explanation === '') {
            return null;
        }

        $candidates = $this->extractCandidates($explanation);

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($this->answersEquivalent($stored, $candidate, $format)) {
                return null;
            }
        }

        $suggested = $candidates[0];

        return [
            'stored_answer' => $stored,
            'suggested_answer' => $suggested,
            'message' => "Stored answer is {$stored}, but the explanation suggests {$suggested}.",
        ];
    }

    /**
     * @return list<string>
     */
    private function extractCandidates(string $explanation): array
    {
        $candidates = [];
        $number = self::NUMBER_PATTERN;

        if (preg_match('/\[Correction[^\]]*?(?:=|is)\s*('.$number.')/iu', $explanation, $match)) {
            $candidates[] = trim($match[1]);
        }

        $patterns = [
            '/(?:product|answer|result|final(?:\s+answer)?)\s*(?:of[^=]{0,120})?=\s*('.$number.')/iu',
            '/(?:answer|result)\s*(?:is|:)\s*('.$number.')/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $explanation, $matches)) {
                foreach ($matches[1] as $value) {
                    $candidates[] = trim($value);
                }
            }
        }

        if (preg_match('/=\s*('.$number.')\s*\.?\s*$/u', trim($explanation), $match)) {
            $candidates[] = trim($match[1]);
        }

        $unique = [];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && ! in_array($candidate, $unique, true)) {
                $unique[] = $candidate;
            }
        }

        return $unique;
    }

    private function answersEquivalent(string $stored, string $expected, ?string $format): bool
    {
        $stored = trim($stored);
        $expected = trim($expected);

        if ($stored === $expected) {
            return true;
        }

        $storedFraction = $this->fractionValue($stored);
        $expectedFraction = $this->fractionValue($expected);

        if ($format === QuestionBlankAnswer::FORMAT_FRACTION
            || ($storedFraction !== null && $expectedFraction !== null && str_contains($stored, '/'))) {
            if ($storedFraction !== null && $expectedFraction !== null) {
                return abs($storedFraction - $expectedFraction) < 0.0001;
            }
        }

        if (is_numeric($stored) && is_numeric($expected)) {
            return abs((float) $stored - (float) $expected) < 0.0001;
        }

        return strcasecmp($stored, $expected) === 0;
    }

    private function fractionValue(string $value): ?float
    {
        $value = trim($value);

        if (preg_match('/^(-?\d+)\s+(\d+)\s*\/\s*(\d+)$/', $value, $match)) {
            return ((int) $match[1]) + ((int) $match[2] / (int) $match[3]);
        }

        if (preg_match('/^(-?\d+)\s*\/\s*(\d+)$/', $value, $match)) {
            return (int) $match[1] / (int) $match[2];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
