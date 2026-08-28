<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\User;

class GeminiPasteVerificationService
{
    public function __construct(
        private ContentVerificationService $verification,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    public function buildPrompt(array $questions, string $chapterLabel): string
    {
        if ($questions === []) {
            return '';
        }

        $lines = [
            'You are reviewing MCQ answers for an Indian school maths app (CBSE/ICSE).',
            'Chapter: '.$chapterLabel,
            '',
            'For EACH question below, check whether the marked correct option is mathematically correct.',
            'Use EXACTLY this format for every question (keep the numbering):',
            '',
            'Question N',
            'Status: Correct',
            'Note: (one short line why, or leave blank if obvious)',
            '',
            'OR',
            '',
            'Question N',
            'Status: Needs Verification',
            'Note: (what is wrong and what the correct answer should be)',
            '',
            'If a question is irrelevant to the chapter, you may use Status: Skip',
            '',
            '---',
            '',
        ];

        foreach ($questions as $row) {
            $number = (int) ($row['number'] ?? 0);
            $lines[] = 'Question '.$number;
            if (! empty($row['set_code'])) {
                $lines[] = 'Set: '.(string) $row['set_code'];
            }
            $lines[] = 'Text: '.(string) ($row['question_text'] ?? '');
            foreach ($row['options'] ?? [] as $option) {
                $mark = ($option['is_correct'] ?? false) ? ' [CORRECT]' : '';
                $lines[] = '  '.($option['letter'] ?? '?').'. '.($option['option_text'] ?? '').$mark;
            }
            if (filled($row['method_hint'] ?? null)) {
                $lines[] = 'Hint: '.(string) $row['method_hint'];
            }
            if (filled($row['explanation'] ?? null)) {
                $lines[] = 'Explanation: '.(string) $row['explanation'];
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function chapterLabel(ContentUploadTask $task): string
    {
        $chapter = $task->textbookChapter;

        if (! $chapter) {
            return 'Chapter';
        }

        $parts = array_filter([
            $chapter->textbook?->gradeLevel?->name,
            $chapter->textbook?->name,
            'Ch '.$chapter->displayChapterNumber().' — '.$chapter->title,
        ], fn ($part) => filled($part));

        return $parts !== [] ? implode(' · ', $parts) : 'Chapter';
    }

    /**
     * Parse Gemini side-panel output into question-number keyed reviews.
     *
     * @return array<int, array{status: string, note: string}>
     */
    public function parseGeminiOutput(string $text): array
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
            return $this->parseStatusLines($text);
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
     * Apply pasted Gemini output: auto-verify Correct, leave Needs Verification for humans.
     *
     * @return array{
     *     reviewed: int,
     *     approved: int,
     *     skipped: int,
     *     needs_attention: int,
     *     unparsed: int,
     *     attention: list<array{question_id: int, number: int, verdict: string, confidence: string, note: string}>,
     *     unparsed_numbers: list<int>
     * }
     */
    public function applyPaste(
        ContentUploadTask $task,
        ContentVerificationRun $run,
        User $user,
        string $paste,
    ): array {
        $payload = $this->verification->forTask($task, $user);
        $pending = collect($payload['questions'])
            ->filter(fn (array $row) => ! $this->verification->isGeminiDoneRow($row))
            ->values();

        if ($pending->isEmpty()) {
            return [
                'reviewed' => 0,
                'approved' => 0,
                'skipped' => 0,
                'needs_attention' => 0,
                'unparsed' => 0,
                'attention' => [],
                'unparsed_numbers' => [],
            ];
        }

        $parsed = $this->parseGeminiOutput($paste);
        $byNumber = $pending->keyBy('number');
        $approvedIds = [];
        $skippedPairs = [];
        $attention = [];
        $unparsedNumbers = [];

        foreach ($pending as $row) {
            $number = (int) $row['number'];
            $review = $parsed[$number] ?? null;

            if ($review === null) {
                $unparsedNumbers[] = $number;

                continue;
            }

            $status = $review['status'];
            $note = $review['note'];
            $verdict = $status === 'correct'
                ? ContentAiVerificationService::VERDICT_APPROVE
                : ($status === 'skip'
                    ? ContentAiVerificationService::VERDICT_SKIP
                    : ContentAiVerificationService::VERDICT_NEEDS_FIX);

            $this->persistGeminiNote($run, (int) $row['question_id'], $verdict, $note);

            if ($status === 'correct') {
                $approvedIds[] = (int) $row['question_id'];
            } elseif ($status === 'skip') {
                $skippedPairs[] = [
                    'question_id' => (int) $row['question_id'],
                    'note' => $note !== '' ? $note : 'Skipped per Gemini review',
                ];
            } else {
                $attention[] = [
                    'question_id' => (int) $row['question_id'],
                    'number' => $number,
                    'verdict' => $verdict,
                    'confidence' => 'high',
                    'note' => $note !== '' ? $note : 'Gemini flagged for manual check.',
                ];
            }
        }

        if ($approvedIds !== []) {
            $this->verification->markVerifiedBatch($run, $approvedIds, $user);
        }

        foreach ($skippedPairs as $pair) {
            $this->verification->skipQuestion($run, $pair['question_id'], $user, $pair['note']);
        }

        return [
            'reviewed' => count($parsed),
            'approved' => count($approvedIds),
            'skipped' => count($skippedPairs),
            'needs_attention' => count($attention),
            'unparsed' => count($unparsedNumbers),
            'attention' => $attention,
            'unparsed_numbers' => $unparsedNumbers,
        ];
    }

    /**
     * @return array<int, array{status: string, note: string}>
     */
    private function parseStatusLines(string $text): array
    {
        $results = [];
        $currentNumber = null;
        $blockLines = [];

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(?:Question|Q)\s*(\d+)\b/i', trim($line), $match)) {
                if ($currentNumber !== null) {
                    $parsed = $this->parseBlock(implode("\n", $blockLines));
                    if ($parsed !== null) {
                        $results[$currentNumber] = $parsed;
                    }
                }

                $currentNumber = (int) $match[1];
                $blockLines = [];

                continue;
            }

            if ($currentNumber !== null) {
                $blockLines[] = $line;
            }
        }

        if ($currentNumber !== null) {
            $parsed = $this->parseBlock(implode("\n", $blockLines));
            if ($parsed !== null) {
                $results[$currentNumber] = $parsed;
            }
        }

        return $results;
    }

    /**
     * @return array{status: string, note: string}|null
     */
    private function parseBlock(string $block): ?array
    {
        $block = trim($block);

        if ($block === '') {
            return null;
        }

        if (preg_match('/Status:\s*\*{0,2}\s*(.+?)\s*\*{0,2}(?:\n|$)/i', $block, $match)) {
            $status = $this->normalizeStatus(trim($match[1]));
            $note = $this->extractNote($block);

            return [
                'status' => $status,
                'note' => $note,
            ];
        }

        if (preg_match('/\b(Needs Verification|Incorrect|Wrong|Needs Fix|Needs Correction)\b/i', $block)) {
            return [
                'status' => 'needs_attention',
                'note' => $this->extractNote($block),
            ];
        }

        if (preg_match('/\b(Correct|Approved|OK|Verified|Accurate)\b/i', $block)) {
            return [
                'status' => 'correct',
                'note' => $this->extractNote($block),
            ];
        }

        return null;
    }

    private function normalizeStatus(string $raw): string
    {
        $lower = strtolower(trim($raw));

        if (preg_match('/^(correct|ok|approved|verified|accurate|valid|looks good)/', $lower)) {
            return 'correct';
        }

        if (preg_match('/^(skip|irrelevant|not relevant|n\/a)/', $lower)) {
            return 'skip';
        }

        return 'needs_attention';
    }

    private function extractNote(string $block): string
    {
        if (preg_match('/(?:^|\n)\s*Note:\s*(.+?)(?:\n(?:Question|Q|\Z)|\z)/is', $block, $match)) {
            return mb_substr(trim($match[1]), 0, 500);
        }

        $withoutStatus = preg_replace('/Status:.+/i', '', $block) ?? $block;

        return mb_substr(trim($withoutStatus), 0, 500);
    }

    private function persistGeminiNote(
        ContentVerificationRun $run,
        int $questionId,
        string $verdict,
        string $note,
    ): void {
        $check = ContentVerificationCheck::query()->firstOrCreate(
            [
                'content_verification_run_id' => $run->id,
                'question_id' => $questionId,
            ],
            ['diagram_note' => 'No diagram needed'],
        );

        $check->update([
            'ai_verdict' => $verdict,
            'ai_confidence' => 'high',
            'ai_note' => $note !== '' ? $note : 'Reviewed via Gemini paste.',
            'ai_reviewed_at' => now(),
        ]);
    }
}
