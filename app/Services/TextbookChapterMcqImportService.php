<?php

namespace App\Services;

use App\Models\TextbookChapter;

class TextbookChapterMcqImportService
{
    public function __construct(
        private McqImportService $mcqImport,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function parseToItems(string $json): array
    {
        $rows = $this->mcqImport->parseJson($json);
        $items = [];

        foreach ($rows as $index => $row) {
            $options = collect($row['options'] ?? []);
            $correct = $options->firstWhere('is_correct', true);
            $mcqOptions = $options
                ->map(fn (array $option) => [
                    'text' => $option['option_text'],
                    'is_correct' => (bool) $option['is_correct'],
                ])
                ->values()
                ->all();

            $topic = trim((string) ($row['topic_name'] ?? ''));
            $label = $topic !== '' ? $topic.' · Q'.($index + 1) : 'Q'.($index + 1);

            $items[] = [
                'id' => 'mcq-'.($index + 1),
                'kind' => 'textbook_mcq',
                'label' => $label,
                'topic' => $topic,
                'source_page' => 0,
                'question_text' => trim((string) $row['question_text']),
                'correct_answer' => trim((string) ($correct['option_text'] ?? '')),
                'answer_format' => 'text',
                'explanation' => trim((string) ($row['explanation'] ?? '')),
                'method_hint' => trim((string) ($row['method_hint'] ?? '')),
                'difficulty' => trim((string) ($row['difficulty'] ?? '')),
                'needs_diagram' => false,
                'include_in_mcq' => true,
                'include_in_written' => false,
                'approved' => true,
                'mcq_options' => $mcqOptions,
            ];
        }

        return $items;
    }

    public function import(TextbookChapter $chapter, string $json): TextbookChapter
    {
        $items = $this->parseToItems($json);

        $chapter->update([
            'extraction_items' => $items,
            'status' => TextbookChapter::STATUS_REVIEW,
            'extracted_at' => now(),
            'extraction_error' => null,
        ]);

        return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']);
    }
}
