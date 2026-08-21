<?php

namespace App\Services;

use App\Models\TextbookChapter;
use App\Support\FillBlankAnswerConsistency;
use InvalidArgumentException;

class TextbookChapterFillBlankImportService
{
    public function __construct(
        private FillBlankImportService $fillBlankImport,
    ) {}

    /**
     * @return array{merged_count: int, total_mcq: int}
     */
    public function import(TextbookChapter $chapter, string $json): array
    {
        $mcqItems = $chapter->extraction_items ?? [];

        if ($mcqItems === []) {
            throw new InvalidArgumentException('Import MCQs first.');
        }

        $rows = $this->fillBlankImport->parseJson($json);
        $merged = 0;

        foreach ($rows as $row) {
            $sourceIndex = (int) ($row['source_index'] ?? ($merged + 1));

            $itemIndex = $sourceIndex - 1;

            if (! isset($mcqItems[$itemIndex])) {
                throw new InvalidArgumentException("Fill-blank row source_index {$sourceIndex} has no matching MCQ (only ".count($mcqItems).' MCQs imported).');
            }

            $questionText = trim((string) ($row['question_text'] ?? ''));

            if (! str_contains($questionText, '____')) {
                throw new InvalidArgumentException("Question {$sourceIndex} must contain ____ for the blank.");
            }

            $mismatch = app(FillBlankAnswerConsistency::class)->mismatch(
                (string) $row['correct_answer'],
                $row['explanation'] ?? null,
                $row['answer_format'],
            );

            if ($mismatch !== null) {
                throw new InvalidArgumentException("Question {$sourceIndex}: {$mismatch['message']}");
            }

            $mcqItems[$itemIndex]['fill_blank_question_text'] = $questionText;
            $mcqItems[$itemIndex]['fill_blank_correct_answer'] = trim((string) $row['correct_answer']);
            $mcqItems[$itemIndex]['fill_blank_answer_format'] = $row['answer_format'];
            $mcqItems[$itemIndex]['fill_blank_decimal_places'] = $row['decimal_places'] ?? null;
            $mcqItems[$itemIndex]['fill_blank_method_hint'] = $row['method_hint'] ?? null;
            $mcqItems[$itemIndex]['fill_blank_explanation'] = $row['explanation'] ?? null;
            $mcqItems[$itemIndex]['include_in_fill_blank'] = true;
            $mcqItems[$itemIndex]['include_in_written'] = true;
            $mcqItems[$itemIndex]['fill_blank_imported_at'] = now()->toIso8601String();
            $merged++;
        }

        $chapter->update(['extraction_items' => array_values($mcqItems)]);

        return [
            'merged_count' => $merged,
            'total_mcq' => count($mcqItems),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function fillBlankReadyCount(array $items): int
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item)
                && filled($item['fill_blank_question_text'] ?? null)
                && filled($item['fill_blank_correct_answer'] ?? null))
            ->count();
    }
}
