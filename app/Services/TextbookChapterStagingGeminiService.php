<?php

namespace App\Services;

use App\Models\TextbookChapter;

class TextbookChapterStagingGeminiService
{
    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function buildPrompt(array $items, string $chapterLabel): string
    {
        $pending = collect($items)
            ->values()
            ->filter(fn (array $item, int $index) => ($item['approved'] ?? true)
                && trim((string) ($item['question_text'] ?? '')) !== ''
                && empty($item['gemini_verified']))
            ->all();

        if ($pending === []) {
            return '';
        }

        $lines = [
            'You are reviewing a mixed maths practice set for an Indian school app (CBSE/ICSE).',
            'Chapter: '.$chapterLabel,
            '',
            'Each question is either:',
            '- Fill in the blank (numeric answer: whole number, decimal, or fraction like 2/3), or',
            '- MCQ with 8 options (A–H).',
            '',
            'For EACH question below, check:',
            '1) Answer correctness (marked [CORRECT] option for MCQ, or fill-blank answer)',
            '2) Figure/diagram: if the question needs a figure from the PDF, confirm needs_diagram is set correctly',
            '',
            'Use EXACTLY this format for every question:',
            '',
            'Question N',
            'Status: Correct',
            'Figure: OK',
            'Note: (optional one line)',
            '',
            'OR',
            '',
            'Question N',
            'Status: Needs Verification',
            'Figure: Missing | OK | Not needed',
            'Note: (what is wrong)',
            '',
            '---',
            '',
        ];

        foreach ($items as $index => $item) {
            if (! ($item['approved'] ?? true) || trim((string) ($item['question_text'] ?? '')) === '') {
                continue;
            }

            if (! empty($item['gemini_verified'])) {
                continue;
            }

            $number = $index + 1;
            $type = ($item['question_type'] ?? '') === 'fill_blank' ? 'Fill in blank' : 'MCQ (8 options)';

            $lines[] = 'Question '.$number.' ['.$type.']';
            $lines[] = 'Text: '.(string) ($item['question_text'] ?? '');

            if (($item['question_type'] ?? '') === 'fill_blank') {
                $lines[] = 'Fill-blank answer: '.(string) ($item['fill_blank_correct_answer'] ?? $item['correct_answer'] ?? '');
            } else {
                foreach ($item['mcq_options'] ?? [] as $optIndex => $option) {
                    $letter = chr(65 + $optIndex);
                    $mark = ($option['is_correct'] ?? false) ? ' [CORRECT]' : '';
                    $lines[] = '  '.$letter.'. '.($option['text'] ?? '').$mark;
                }
            }

            $needsDiagram = filter_var($item['needs_diagram'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $hasFigure = filled($item['diagram_staging_path'] ?? null) || filled($item['diagram_preview_url'] ?? null);
            $lines[] = 'needs_diagram: '.($needsDiagram ? 'true' : 'false').($hasFigure ? ' (image uploaded)' : '');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function chapterLabel(TextbookChapter $chapter): string
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);

        $parts = array_filter([
            $chapter->textbook?->gradeLevel?->name,
            $chapter->textbook?->name,
            'Ch '.$chapter->displayChapterNumber().' — '.$chapter->title,
        ], fn ($part) => filled($part));

        return $parts !== [] ? implode(' · ', $parts) : 'Chapter';
    }

    /**
     * @return array{
     *     reviewed: int,
     *     approved: int,
     *     needs_attention: int,
     *     unparsed: int,
     *     attention: list<array{number: int, note: string, figure: string}>,
     *     unparsed_numbers: list<int>
     * }
     */
    public function applyPaste(TextbookChapter $chapter, string $paste): array
    {
        $items = array_values(array_filter(
            is_array($chapter->extraction_items) ? $chapter->extraction_items : [],
            fn ($item) => is_array($item),
        ));

        $parsed = $this->parseGeminiOutput($paste);
        $approved = 0;
        $attention = [];
        $unparsedNumbers = [];
        $reviewed = 0;

        foreach ($items as $index => $item) {
            if (! ($item['approved'] ?? true) || trim((string) ($item['question_text'] ?? '')) === '') {
                continue;
            }

            if (! empty($item['gemini_verified'])) {
                continue;
            }

            $number = $index + 1;
            $review = $parsed[$number] ?? null;

            if ($review === null) {
                $unparsedNumbers[] = $number;

                continue;
            }

            $reviewed++;
            $status = $review['status'];
            $figure = strtolower($review['figure']);
            $note = $review['note'];

            $items[$index]['gemini_note'] = $note;
            $items[$index]['gemini_figure_status'] = $figure;

            $figureOk = in_array($figure, ['ok', 'not needed', 'not_needed', 'n/a', 'na'], true);
            $needsDiagram = filter_var($item['needs_diagram'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $hasFigure = filled($item['diagram_staging_path'] ?? null);

            if ($needsDiagram && ! $hasFigure && ! in_array($figure, ['ok', 'not needed', 'not_needed'], true)) {
                $items[$index]['gemini_verified'] = false;
                $attention[] = [
                    'number' => $number,
                    'note' => $note !== '' ? $note : 'Figure required but not uploaded',
                    'figure' => $figure,
                ];

                continue;
            }

            if ($status === 'correct' && ($figureOk || ! $needsDiagram)) {
                $items[$index]['gemini_verified'] = true;
                $approved++;
            } else {
                $items[$index]['gemini_verified'] = false;
                $attention[] = [
                    'number' => $number,
                    'note' => $note !== '' ? $note : 'Needs verification',
                    'figure' => $figure,
                ];
            }
        }

        $chapter->update(['extraction_items' => $items]);

        return [
            'reviewed' => $reviewed,
            'approved' => $approved,
            'needs_attention' => count($attention),
            'unparsed' => count($unparsedNumbers),
            'attention' => $attention,
            'unparsed_numbers' => $unparsedNumbers,
        ];
    }

    public function resetGeminiReview(TextbookChapter $chapter): void
    {
        $items = array_values(array_filter(
            is_array($chapter->extraction_items) ? $chapter->extraction_items : [],
            fn ($item) => is_array($item),
        ));

        foreach ($items as $index => $item) {
            unset(
                $items[$index]['gemini_verified'],
                $items[$index]['gemini_note'],
                $items[$index]['gemini_figure_status'],
            );
        }

        $chapter->update(['extraction_items' => $items]);
    }

    /**
     * @return array<int, array{status: string, figure: string, note: string}>
     */
    private function parseGeminiOutput(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        if ($text === '') {
            return [];
        }

        preg_match_all(
            '/(?:^|\n)\s*(?:\*{0,2})?(?:Question|Q)\s*(\d+)(?:\s+Analysis)?:?(?:\*{0,2})?\s*(?:\n|$)/i',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if ($matches[0] === []) {
            return [];
        }

        $headers = [];

        for ($i = 0; $i < count($matches[0]); $i++) {
            $headers[] = [
                'number' => (int) $matches[1][$i][0],
                'start' => $matches[0][$i][1],
                'header_len' => strlen($matches[0][$i][0]),
            ];
        }

        $results = [];

        for ($i = 0; $i < count($headers); $i++) {
            $start = $headers[$i]['start'] + $headers[$i]['header_len'];
            $end = isset($headers[$i + 1]) ? $headers[$i + 1]['start'] : strlen($text);
            $block = substr($text, $start, max(0, $end - $start));
            $parsed = $this->parseBlock($block);

            if ($parsed !== null) {
                $results[$headers[$i]['number']] = $parsed;
            }
        }

        return $results;
    }

    /**
     * @return array{status: string, figure: string, note: string}|null
     */
    private function parseBlock(string $block): ?array
    {
        $status = null;
        $figure = '';
        $note = '';

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);

            if (preg_match('/^status:\s*(.+)$/i', $line, $m)) {
                $raw = strtolower(trim($m[1]));
                $status = str_contains($raw, 'correct') && ! str_contains($raw, 'needs')
                    ? 'correct'
                    : (str_contains($raw, 'skip') ? 'skip' : 'needs_verification');
            } elseif (preg_match('/^figure:\s*(.+)$/i', $line, $m)) {
                $figure = strtolower(trim($m[1]));
            } elseif (preg_match('/^note:\s*(.*)$/i', $line, $m)) {
                $note = trim($m[1]);
            }
        }

        if ($status === null) {
            return null;
        }

        return [
            'status' => $status,
            'figure' => $figure,
            'note' => $note,
        ];
    }
}
