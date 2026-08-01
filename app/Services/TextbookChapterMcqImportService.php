<?php

namespace App\Services;

use App\Models\TextbookChapter;
use InvalidArgumentException;

class TextbookChapterMcqImportService
{
    public function __construct(
        private McqImportService $mcqImport,
        private TextbookMcqSetPlanService $setPlanService,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, payload: array<string, mixed>}
     */
    public function parsePayload(string $json): array
    {
        $payload = $this->decodePayload($json);
        $items = $this->parseToItemsFromPayload($payload);

        return [
            'items' => $items,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseToItems(string $json): array
    {
        return $this->parsePayload($json)['items'];
    }

    public function import(TextbookChapter $chapter, string $json): TextbookChapter
    {
        $parsed = $this->parsePayload($json);
        $items = $parsed['items'];
        $setPlan = $this->setPlanService->defaultPlan($chapter, count($items));

        $chapter->update([
            'extraction_items' => $items,
            'mcq_set_plan' => $setPlan,
            'status' => TextbookChapter::STATUS_REVIEW,
            'extracted_at' => now(),
            'extraction_error' => null,
        ]);

        return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function parseToItemsFromPayload(array $payload): array
    {
        $rows = isset($payload['questions']) && is_array($payload['questions'])
            ? $payload['questions']
            : $payload;

        if (! is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('No questions found in JSON.');
        }

        $items = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeImportedRow($row, $index);
            if (trim((string) ($normalized['question_text'] ?? '')) === '') {
                continue;
            }

            $items[] = $normalized;
        }

        if ($items === []) {
            throw new InvalidArgumentException('Could not parse any questions from JSON.');
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeImportedRow(array $row, int $index): array
    {
        $options = collect($row['options'] ?? []);
        $correctIndex = isset($row['correct_index']) ? (int) $row['correct_index'] : null;

        $mcqOptions = $options
            ->values()
            ->map(function ($option, $optIndex) use ($correctIndex) {
                if (is_array($option)) {
                    return [
                        'text' => trim((string) ($option['text'] ?? $option['option_text'] ?? '')),
                        'is_correct' => (bool) ($option['is_correct'] ?? false) || $correctIndex === $optIndex,
                    ];
                }

                return [
                    'text' => trim((string) $option),
                    'is_correct' => $correctIndex === $optIndex,
                ];
            })
            ->filter(fn (array $option) => $option['text'] !== '')
            ->values()
            ->all();

        $correct = collect($mcqOptions)->firstWhere('is_correct', true);
        $topic = trim((string) ($row['topic'] ?? $row['topic_name'] ?? ''));
        $label = $topic !== '' ? $topic.' · Q'.($index + 1) : 'Q'.($index + 1);

        return [
            'id' => 'mcq-'.($index + 1),
            'kind' => 'textbook_mcq',
            'label' => $label,
            'topic' => $topic,
            'source_page' => 0,
            'question_text' => $this->composeQuestionText($row),
            'correct_answer' => trim((string) ($correct['text'] ?? '')),
            'answer_format' => 'text',
            'explanation' => trim((string) ($row['explanation'] ?? '')),
            'method_hint' => trim((string) ($row['method_hint'] ?? $row['hint'] ?? '')),
            'difficulty' => trim((string) ($row['difficulty'] ?? '')),
            'needs_diagram' => false,
            'include_in_mcq' => true,
            'include_in_written' => false,
            'approved' => true,
            'mcq_options' => $mcqOptions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $json): array
    {
        $json = trim($json);

        if (preg_match('/^```(?:json)?\s*(.*?)```\s*$/is', $json, $matches)) {
            $json = trim($matches[1]);
        } else {
            $json = preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/', '', $json) ?? $json) ?? $json;
        }

        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{\s*"questions"\s*:\s*\[[\s\S]*\]\s*(?:,\s*"set_plan"\s*:\s*\[[\s\S]*?\]\s*)?\}/', $json, $matches)) {
            $data = json_decode($matches[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        throw new InvalidArgumentException('Invalid JSON. Paste {"questions": [...], "set_plan": [...]} from Cursor.');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function composeQuestionText(array $row): string
    {
        $parts = [];

        $question = trim((string) ($row['question'] ?? $row['question_text'] ?? ''));
        if ($question !== '') {
            $parts[] = $question;
        }

        foreach (['context', 'passage', 'intro'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        foreach (['chart', 'chart_description', 'figure_description', 'graph'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = "Chart:\n".$value;
            }
        }

        foreach (['table', 'data_table', 'table_markdown', 'chart_table'] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $formatted = is_array($row[$key])
                ? $this->formatTableValue($row[$key])
                : trim((string) $row[$key]);

            if ($formatted !== '') {
                $parts[] = "Table:\n".$formatted;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  array<int|string, mixed>  $table
     */
    private function formatTableValue(array $table): string
    {
        if (isset($table['headers'], $table['rows'])
            && is_array($table['headers'])
            && is_array($table['rows'])) {
            $headers = array_map(strval(...), $table['headers']);
            $lines = [
                implode(' | ', $headers),
                str_repeat('-', min(80, max(12, strlen(implode(' | ', $headers))))),
            ];

            foreach ($table['rows'] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $lines[] = implode(' | ', array_map(strval(...), $row));
            }

            return implode("\n", $lines);
        }

        $rows = array_values($table);
        if ($rows !== [] && is_array($rows[0])) {
            $headers = array_keys($rows[0]);
            $lines = [
                implode(' | ', $headers),
                str_repeat('-', min(80, max(12, strlen(implode(' | ', $headers))))),
            ];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $lines[] = implode(' | ', array_map(
                    fn (string $header) => (string) ($row[$header] ?? ''),
                    $headers,
                ));
            }

            return implode("\n", $lines);
        }

        $encoded = json_encode($table, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }
}
