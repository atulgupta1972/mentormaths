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
            'question_text' => trim((string) ($row['question'] ?? $row['question_text'] ?? '')),
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
}
