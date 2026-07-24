<?php

namespace App\Services;

use InvalidArgumentException;

class WrittenSheetAnswerKeyParser
{
    public const MAX_ANSWER_LENGTH = 64;

    /**
     * @return array{
     *     rows: list<array{correct_answer: string, answer_format: string, method_hint: string|null}>,
     *     warnings: list<string>,
     *     parsed_count: int,
     * }
     */
    public function parse(string $text): array
    {
        $normalized = $this->normalize($text);
        $section = $this->extractAnswerSection($normalized);
        $pairs = $this->extractNumberedAnswers($section);

        if ($pairs === []) {
            throw new InvalidArgumentException(
                'Could not find numbered answers in the answer sheet PDF. Use lines like 1. 42, Q2: 3/4, or blocks with "Correct Answer: …".',
            );
        }

        ksort($pairs, SORT_NUMERIC);

        $rows = [];
        $warnings = [];

        foreach ($pairs as $number => $entry) {
            $answer = $entry['answer'];
            $finalAnswer = $this->finalizeAnswer($answer);

            if ($finalAnswer === '') {
                $warnings[] = "Question {$number}: could not extract a short answer — edit row {$number} manually.";

                continue;
            }

            if (mb_strlen($answer) > self::MAX_ANSWER_LENGTH) {
                $warnings[] = 'Question '.$number.': answer trimmed to '.self::MAX_ANSWER_LENGTH.' characters ('.$finalAnswer.').';
            }

            $rows[] = [
                'correct_answer' => $finalAnswer,
                'answer_format' => $this->inferFormat($finalAnswer),
                'method_hint' => $entry['method_hint'],
            ];
        }

        if ($rows === []) {
            throw new InvalidArgumentException(
                'Answers were found but none could be mapped to short correct answers. Use "Correct Answer: …" in the PDF or enter answers manually.',
            );
        }

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'parsed_count' => count($rows),
        ];
    }

    /**
     * @return array{rows: array, warnings: list<string>, parsed_count: int}
     */
    public function parseWithExpectedCount(string $text, ?int $expectedCount): array
    {
        $result = $this->parse($text);

        if ($expectedCount !== null && $expectedCount > 0 && $result['parsed_count'] !== $expectedCount) {
            $result['warnings'][] = "Found {$result['parsed_count']} answer(s) but the worksheet PDF looks like it has {$expectedCount} question(s). Check the mapping below.";
        }

        return $result;
    }

    public function estimateQuestionCountFromWorksheet(string $text): ?int
    {
        $normalized = $this->normalize($text);
        $sections = preg_split('/(?:answer\s*key|answers\s*key|solution\s*key)/iu', $normalized, 2);
        $body = trim((string) ($sections[0] ?? $normalized));

        if ($body === '') {
            return null;
        }

        preg_match_all('/(?:^|\s)(?:Q(?:uestion)?\s*)?(\d{1,3})\s*[.)]\s+/iu', $body, $matches);

        if ($matches[1] === []) {
            return null;
        }

        $numbers = array_map('intval', $matches[1]);

        return max($numbers);
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Keep tabs — answer-key tables are often "Q\tQuestion\tAnswer".
        $text = preg_replace('/[ ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function extractAnswerSection(string $text): string
    {
        if (preg_match('/(?:answer\s*key|answers\s*key|solution\s*key|marking\s*scheme)\s*:?\s*(.+)$/ius', $text, $match)) {
            return trim($match[1]);
        }

        if (preg_match('/(?:^|\n)\s*(?:answers|solutions)\s*:?\s*(.+)$/ius', $text, $match)) {
            return trim($match[1]);
        }

        return $text;
    }

    /**
     * @return array<int, array{answer: string, method_hint: string|null}>
     */
    private function extractNumberedAnswers(string $text): array
    {
        $tableAnswers = $this->extractTabularAnswers($text);

        if ($tableAnswers !== []) {
            return $tableAnswers;
        }

        $answers = [];

        preg_match_all('/(\d{1,3})\.\s*([a-d])\b/iu', $text, $mcqMatches, PREG_SET_ORDER);

        foreach ($mcqMatches as $match) {
            $answers[(int) $match[1]] = [
                'answer' => strtolower($match[2]),
                'method_hint' => null,
            ];
        }

        preg_match_all(
            '/(?:^|\n)\s*(\d{1,3})\.\s*(.+?)(?=(?:^|\n)\s*\d{1,3}\.\s|\z)/ius',
            $text,
            $blockMatches,
            PREG_SET_ORDER,
        );

        foreach ($blockMatches as $match) {
            $number = (int) $match[1];

            if (isset($answers[$number])) {
                continue;
            }

            [$answer, $methodHint] = $this->extractAnswerAndHint((string) $match[2]);

            if ($answer !== '') {
                $answers[$number] = [
                    'answer' => $answer,
                    'method_hint' => $methodHint,
                ];
            }
        }

        $remaining = preg_replace('/(\d{1,3})\.\s*[a-d]\b/iu', '', $text) ?? $text;
        $remaining = preg_replace('/(?:^|\n)\s*\d{1,3}\.\s*.+?(?=(?:^|\n)\s*\d{1,3}\.\s|\z)/ius', '', $remaining) ?? $remaining;
        $remaining = preg_replace('/\s+(?=Q(?:uestion)?\s*\d)/iu', "\n", $remaining) ?? $remaining;

        foreach (preg_split('/\n+/u', trim($remaining)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Do not treat "-" as a number delimiter — answers like "-18 + …" start with a minus.
            if (preg_match('/^(?:Q(?:uestion)?\s*)?(\d{1,3})\s*[.):]\s*(.+)$/iu', $line, $match)) {
                $number = (int) $match[1];

                if (isset($answers[$number])) {
                    continue;
                }

                [$answer, $methodHint] = $this->extractAnswerAndHint((string) $match[2]);

                if ($answer !== '') {
                    $answers[$number] = [
                        'answer' => $answer,
                        'method_hint' => $methodHint,
                    ];
                }

                continue;
            }

            preg_match_all(
                '/(\d{1,3})\.\s*(.+?)(?=\s+\d{1,3}\.\s|$)/iu',
                $line,
                $inlineMatches,
                PREG_SET_ORDER,
            );

            foreach ($inlineMatches as $inlineMatch) {
                $number = (int) $inlineMatch[1];

                if (isset($answers[$number])) {
                    continue;
                }

                [$answer, $methodHint] = $this->extractAnswerAndHint((string) $inlineMatch[2]);

                if ($answer !== '') {
                    $answers[(int) $inlineMatch[1]] = [
                        'answer' => $answer,
                        'method_hint' => $methodHint,
                    ];
                }
            }
        }

        return $answers;
    }

    /**
     * Parse table rows like: 1\t11 + 12 - (-12)\t54
     * Also handles space-separated rows and fully flattened one-line tables.
     *
     * @return array<int, array{answer: string, method_hint: string|null}>
     */
    private function extractTabularAnswers(string $text): array
    {
        $answers = $this->extractTabularAnswersFromLines($text);

        if ($answers !== []) {
            return $answers;
        }

        return $this->extractTabularAnswersFromFlattenedText($text);
    }

    /**
     * @return array<int, array{answer: string, method_hint: string|null}>
     */
    private function extractTabularAnswersFromLines(string $text): array
    {
        $answers = [];

        foreach (preg_split('/\n+/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^note\s*:/iu', $line)) {
                continue;
            }

            if (preg_match('/^q\.?\b.*\banswer\b/iu', $line) && ! preg_match('/^\d/u', $line)) {
                continue;
            }

            // Strict tab-separated: number | question/expression | answer
            if (preg_match('/^(\d{1,3})\t([^\t]+)\t([^\t]+)$/u', $line, $match)) {
                $answer = $this->cleanAnswer(trim($match[3]));

                if ($answer !== '') {
                    $answers[(int) $match[1]] = [
                        'answer' => $answer,
                        'method_hint' => null,
                    ];
                }

                continue;
            }

            // Space-separated table row: Qn + expression + short final answer token
            if (preg_match('/^(\d{1,3})\s+(.+)$/u', $line, $match)) {
                $number = (int) $match[1];
                $rest = trim($match[2]);

                if (preg_match('/^(.*)\s+(\S{1,32})$/u', $rest, $parts)) {
                    $candidate = $this->cleanAnswer(trim($parts[2]));

                    if ($this->looksLikeShortTableAnswer($candidate)) {
                        $answers[$number] = [
                            'answer' => $candidate,
                            'method_hint' => null,
                        ];
                    }
                }
            }
        }

        return $answers;
    }

    /**
     * When PDF extraction flattened all whitespace into one line.
     * Walk Q1, Q2, … in order and take the last token of each chunk as the answer.
     *
     * @return array<int, array{answer: string, method_hint: string|null}>
     */
    private function extractTabularAnswersFromFlattenedText(string $text): array
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (! preg_match('/\bAnswer\b/iu', $flat) || ! preg_match('/\bQuestion\b/iu', $flat)) {
            return [];
        }

        if (! preg_match('/\bAnswer\b\s*(.+)$/iu', $flat, $afterHeader)) {
            return [];
        }

        $body = trim($afterHeader[1]);
        $body = preg_replace('/\s+Note:.*$/iu', '', $body) ?? $body;

        $answers = [];
        $offset = 0;

        for ($number = 1; $number <= 200; $number++) {
            if (! preg_match('/(?:^|\s)'.$number.'\s+/u', $body, $startMatch, PREG_OFFSET_CAPTURE, $offset)) {
                break;
            }

            $contentStart = $startMatch[0][1] + strlen($startMatch[0][0]);
            $nextNumber = $number + 1;
            $contentEnd = strlen($body);

            if (preg_match(
                '/\s'.$nextNumber.'\s+(?=(?:-?\d|\(|\[))/u',
                $body,
                $nextMatch,
                PREG_OFFSET_CAPTURE,
                $contentStart,
            )) {
                $contentEnd = $nextMatch[0][1];
                $offset = $nextMatch[0][1];
            } else {
                $offset = strlen($body);
            }

            $chunk = trim(substr($body, $contentStart, $contentEnd - $contentStart));

            if (! preg_match('/(\S+)$/u', $chunk, $tokenMatch)) {
                break;
            }

            $answer = $this->cleanAnswer($tokenMatch[1]);

            if (! $this->looksLikeShortTableAnswer($answer)) {
                break;
            }

            $answers[$number] = [
                'answer' => $answer,
                'method_hint' => null,
            ];

            if ($offset >= strlen($body)) {
                break;
            }
        }

        return $answers;
    }

    private function looksLikeShortTableAnswer(string $value): bool
    {
        if ($value === '' || mb_strlen($value) > 32) {
            return false;
        }

        if (str_contains($value, '?')) {
            return false;
        }

        // Prefer numeric / fraction / short MCQ-style answers for table keys.
        return (bool) preg_match(
            '/^(?:-?\d+(?:\.\d+)?|-?\d+\s*\/\s*\d+|-?\d+\s+\d+\s*\/\s*\d+|[a-d]|[<=>≤≥≠]+|°|[A-Za-z]\s*=\s*-?\d+(?:\.\d+)?)$/u',
            $value,
        );
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function extractAnswerAndHint(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^\[(?:easy|medium|hard)\]\s*/iu', '', $content) ?? $content;

        $methodHint = null;

        if (preg_match('/\bexplanation\s*:?\s*(.+)$/iu', $content, $explanationMatch)) {
            $methodHint = trim($explanationMatch[1]);
            $content = preg_replace('/\s+explanation\s*:?.+$/iu', '', $content) ?? $content;
        }

        if (preg_match('/\bcorrect\s*answer\s*:?\s*(.+)$/iu', $content, $answerMatch)) {
            return [trim($answerMatch[1]), $methodHint];
        }

        if (preg_match('/\bans(?:wer)?\s*:?\s*(.+)$/iu', $content, $answerMatch)) {
            return [trim($answerMatch[1]), $methodHint];
        }

        return [$this->cleanAnswer($content), $methodHint];
    }

    private function cleanAnswer(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s*(?:marks?|mark|pts?)\s*:?\s*\d+\s*$/iu', '', $value) ?? $value;
        $value = preg_replace('/^(?:ans(?:wer)?|correct(?:\s+answer)?)\s*:?\s*/iu', '', $value) ?? $value;
        $value = trim($value);
        $value = preg_replace('/^[.\s]+|[.\s]+$/u', '', $value) ?? $value;

        if (preg_match('/^([a-d])\s*[\).:-]?\s*$/iu', $value, $letterOnly)) {
            return strtolower($letterOnly[1]);
        }

        if (str_contains($value, '?') && mb_strlen($value) > 40) {
            return '';
        }

        return trim($value);
    }

    private function finalizeAnswer(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        if (mb_strlen($value) <= self::MAX_ANSWER_LENGTH) {
            return $value;
        }

        return trim(mb_substr($value, 0, self::MAX_ANSWER_LENGTH));
    }

    private function inferFormat(string $answer): string
    {
        if (preg_match('/^(<=|>=|!=|[<=>≤≥≠])$/u', $answer)) {
            return 'text';
        }

        if (preg_match('/^-?\d+$/', $answer)) {
            return 'integer';
        }

        if (preg_match('/^-?\d+\s+\d+\s*\/\s*\d+$/', $answer) || preg_match('/^-?\d+\s*\/\s*\d+$/', $answer)) {
            return 'fraction';
        }

        if (preg_match('/^-?\d+(?:\.\d+)?°?$/u', $answer) && str_contains($answer, '.')) {
            return 'decimal';
        }

        if (preg_match('/^[a-d]$/iu', $answer)) {
            return 'text';
        }

        return 'text';
    }
}
