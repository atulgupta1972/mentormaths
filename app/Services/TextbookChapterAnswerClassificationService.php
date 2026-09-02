<?php

namespace App\Services;

use App\Models\QuestionBlankAnswer;
use InvalidArgumentException;

class TextbookChapterAnswerClassificationService
{
    /** @var array<string, string> */
    private const UNIT_LABELS = [
        'm' => 'metres',
        'metre' => 'metres',
        'metres' => 'metres',
        'meter' => 'metres',
        'meters' => 'metres',
        'cm' => 'centimetres',
        'centimetre' => 'centimetres',
        'centimetres' => 'centimetres',
        'mm' => 'millimetres',
        'km' => 'kilometres',
        'g' => 'grams',
        'gram' => 'grams',
        'grams' => 'grams',
        'kg' => 'kilograms',
        'kilogram' => 'kilograms',
        'kilograms' => 'kilograms',
        'mg' => 'milligrams',
        'l' => 'litres',
        'L' => 'litres',
        'litre' => 'litres',
        'litres' => 'litres',
        'liter' => 'litres',
        'liters' => 'litres',
        'ml' => 'millilitres',
        'mL' => 'millilitres',
        '°c' => '°C',
        '°C' => '°C',
        'deg c' => '°C',
        'degree c' => '°C',
        'degrees c' => '°C',
        '°' => 'degrees',
        'degree' => 'degrees',
        'degrees' => 'degrees',
        'cm²' => 'cm²',
        'cm2' => 'cm²',
        'm²' => 'm²',
        'm2' => 'm²',
        'km²' => 'km²',
        'km2' => 'km²',
        'sq cm' => 'cm²',
        'sq m' => 'm²',
        's' => 'seconds',
        'sec' => 'seconds',
        'seconds' => 'seconds',
        'min' => 'minutes',
        'minutes' => 'minutes',
        'h' => 'hours',
        'hr' => 'hours',
        'hours' => 'hours',
        '₹' => 'rupees',
        'rs' => 'rupees',
        'rupees' => 'rupees',
        'rs.' => 'rupees',
        '%' => 'percent',
        'percent' => 'percent',
    ];

    public function looksLikeWordAnswer(?string $answer): bool
    {
        $value = trim((string) $answer);

        if ($value === '') {
            return false;
        }

        if ($this->isMixedFraction($value)) {
            return true;
        }

        $parsed = $this->parseNumericWithUnit($value);
        if ($parsed['numeric'] !== null) {
            return false;
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
        $value = $this->normalizeNumericCharacters(trim((string) $answer));

        if ($value === '') {
            return false;
        }

        if ($this->isTrueFalseAnswer($value) || $this->isMixedFraction($value)) {
            return false;
        }

        $parsed = $this->parseNumericWithUnit($value);
        if ($parsed['numeric'] !== null) {
            return $this->detectAnswerFormat($parsed['numeric']) !== null;
        }

        if ($this->looksLikeWordAnswer($value)) {
            return false;
        }

        return $this->detectAnswerFormat($value) !== null;
    }

    public function detectAnswerFormat(string $answer): ?string
    {
        $answer = $this->normalizeNumericCharacters($answer);
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
     * @return array{numeric: ?string, unit: ?string, unit_label: ?string}
     */
    public function parseNumericWithUnit(string $answer): array
    {
        $value = $this->normalizeNumericCharacters(trim($answer));

        if (preg_match('/^(-?[\d,]+(?:\.\d+)?)\s*(.+)$/u', $value, $matches)) {
            $numeric = str_replace(',', '', $matches[1]);
            $unitRaw = trim($matches[2]);
            $unitKey = strtolower(rtrim($unitRaw, '.'));

            if ($this->detectAnswerFormat($numeric) !== null && isset(self::UNIT_LABELS[$unitKey])) {
                return [
                    'numeric' => $numeric,
                    'unit' => $unitRaw,
                    'unit_label' => self::UNIT_LABELS[$unitKey],
                ];
            }
        }

        return ['numeric' => null, 'unit' => null, 'unit_label' => null];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function applyMixedClassification(array $item): array
    {
        $item = $this->normalizeCorrectAnswer($item);
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
     * @return array<string, mixed>
     */
    public function convertItemToFillBlank(array $item): array
    {
        $item = $this->normalizeCorrectAnswer($item);
        $answer = trim((string) ($item['correct_answer'] ?? ''));

        if (! $this->shouldBeFillBlank($answer)) {
            throw new InvalidArgumentException(
                'This answer is not a plain number or fraction. Keep it as MCQ, or rewrite the stem with ____ and a numeric answer.',
            );
        }

        return $this->applyMixedClassification($item);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function revertItemToMcq(array $item): array
    {
        $item['question_type'] = 'mcq';
        $item['include_in_mcq'] = true;
        $item['include_in_written'] = false;
        $item['include_in_fill_blank'] = false;

        return $item;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{question_text: string, correct_answer: string, answer_format: string, decimal_places: int|null}
     */
    private function fillBlankPrefill(array $item): array
    {
        $mcqAnswer = $this->normalizeNumericCharacters(trim((string) ($item['correct_answer'] ?? '')));
        $stem = trim((string) ($item['question_text'] ?? ''));
        $explicitUnit = trim((string) ($item['answer_unit'] ?? ''));
        $format = QuestionBlankAnswer::FORMAT_TEXT;
        $places = null;
        $unitLabel = null;

        $parsed = $this->parseNumericWithUnit($mcqAnswer);
        if ($parsed['numeric'] !== null) {
            $mcqAnswer = $parsed['numeric'];
            $unitLabel = $parsed['unit_label'];
        }

        if ($explicitUnit !== '') {
            $unitKey = strtolower(rtrim($explicitUnit, '.'));
            $unitLabel = self::UNIT_LABELS[$unitKey] ?? $explicitUnit;
        }

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
            if ($unitLabel !== null) {
                $stem = rtrim($stem, " \t\n\r\0\x0B.:?")." is ____ {$unitLabel}.";
            } else {
                $stem = rtrim($stem, " \t\n\r\0\x0B.?").' The answer is ____.';
            }
        }

        return [
            'question_text' => $stem,
            'correct_answer' => $mcqAnswer,
            'answer_format' => $format,
            'decimal_places' => $places,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeCorrectAnswer(array $item): array
    {
        $answer = trim((string) ($item['correct_answer'] ?? ''));

        if ($answer === '') {
            $correct = collect($item['mcq_options'] ?? [])->firstWhere('is_correct', true);
            $answer = trim((string) ($correct['text'] ?? ''));
            if ($answer !== '') {
                $item['correct_answer'] = $answer;
            }
        }

        $parsed = $this->parseNumericWithUnit($answer);
        if ($parsed['numeric'] !== null) {
            $item['correct_answer'] = $parsed['numeric'];
            if (empty($item['answer_unit']) && $parsed['unit_label'] !== null) {
                $item['answer_unit'] = $parsed['unit'];
            }
        }

        return $item;
    }

    private function normalizeNumericCharacters(string $value): string
    {
        return str_replace(
            ["\u{2212}", '−', '–', '—'],
            '-',
            $value,
        );
    }
}
