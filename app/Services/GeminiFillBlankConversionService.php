<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;
use App\Support\FillBlankStem;
use App\Support\StemSimilarity;

class GeminiFillBlankConversionService
{
    public function __construct(
        private TextbookChapterConversionPromptService $prompts,
        private FillBlankImportService $fillBlankImport,
        private FillBlankConversionService $conversion,
        private StemSimilarity $similarity,
    ) {}

    /**
     * @return array{
     *     prompt: string,
     *     sample_json: string,
     *     mcq_reference_json: string,
     *     question_count: int,
     *     fill_blank_set_code: string,
     *     written_set_code: string,
     *     fill_blank_set_codes: list<string>,
     *     written_set_codes: list<string>,
     *     practice_line: string,
     *     is_mentormaths: bool,
     *     transform_required: bool
     * }
     */
    public function payload(TextbookChapter $chapter): array
    {
        return $this->prompts->payload($chapter, includeMcqReferenceJson: true);
    }

    /**
     * @return array{
     *     total: int,
     *     convertible_count: int,
     *     not_possible_count: int,
     *     blocked_count: int,
     *     convertible: list<array<string, mixed>>,
     *     not_possible: list<array<string, mixed>>,
     *     blocked: list<array<string, mixed>>,
     *     is_mentormaths: bool,
     *     transform_required: bool
     * }
     */
    public function preview(TextbookChapter $chapter, string $json): array
    {
        $chapter->loadMissing('textbook');
        $items = array_values(array_filter(
            is_array($chapter->extraction_items) ? $chapter->extraction_items : [],
            fn ($item) => is_array($item),
        ));

        $parsed = $this->fillBlankImport->parseJson($json);
        $convertibleIndexes = [];
        $blockedIndexes = [];
        $convertible = [];
        $blocked = [];
        $isMentorMaths = $chapter->textbook?->isMentorMathsPracticeLine() ?? false;

        foreach ($parsed as $row) {
            $sourceIndex = (int) ($row['source_index'] ?? 0);
            $itemIndex = $sourceIndex - 1;

            if ($itemIndex < 0 || $itemIndex >= count($items)) {
                throw new \InvalidArgumentException("Fill-blank row source_index {$sourceIndex} has no matching MCQ.");
            }

            if (in_array($itemIndex, $convertibleIndexes, true) || in_array($itemIndex, $blockedIndexes, true)) {
                continue;
            }

            $fillBlankQuestion = FillBlankStem::normalize((string) ($row['question_text'] ?? ''));
            $sourceStem = (string) ($items[$itemIndex]['question_text'] ?? '');

            if (! FillBlankStem::hasBlank($fillBlankQuestion)) {
                $blockedIndexes[] = $itemIndex;
                $blocked[] = [
                    'index' => $itemIndex,
                    'number' => $sourceIndex,
                    'label' => trim((string) ($items[$itemIndex]['label'] ?? $items[$itemIndex]['topic'] ?? '')),
                    'mcq_question' => $sourceStem,
                    'fill_blank_question' => $fillBlankQuestion,
                    'reason' => 'Missing ____ blank in the question stem.',
                    'overlap' => null,
                ];

                continue;
            }

            $similarityResult = $this->similarity->compare($sourceStem, $fillBlankQuestion);

            if ($isMentorMaths && $similarityResult['too_similar']) {
                $blockedIndexes[] = $itemIndex;
                $blocked[] = [
                    'index' => $itemIndex,
                    'number' => $sourceIndex,
                    'label' => trim((string) ($items[$itemIndex]['label'] ?? $items[$itemIndex]['topic'] ?? '')),
                    'mcq_question' => $sourceStem,
                    'fill_blank_question' => $fillBlankQuestion,
                    'reason' => $similarityResult['reason'],
                    'overlap' => $similarityResult['overlap'],
                ];

                continue;
            }

            $convertibleIndexes[] = $itemIndex;
            $convertible[] = [
                'index' => $itemIndex,
                'number' => $sourceIndex,
                'label' => trim((string) ($items[$itemIndex]['label'] ?? $items[$itemIndex]['topic'] ?? '')),
                'mcq_question' => $sourceStem,
                'mcq_answer' => (string) ($items[$itemIndex]['correct_answer'] ?? ''),
                'fill_blank_question' => $fillBlankQuestion,
                'correct_answer' => (string) ($row['correct_answer'] ?? ''),
                'answer_format' => (string) ($row['answer_format'] ?? ''),
                'similarity_overlap' => $similarityResult['overlap'],
            ];
        }

        $notPossible = [];

        foreach ($items as $index => $item) {
            if (in_array($index, $convertibleIndexes, true) || in_array($index, $blockedIndexes, true)) {
                continue;
            }

            $notPossible[] = [
                'index' => $index,
                'number' => $index + 1,
                'label' => trim((string) ($item['label'] ?? $item['topic'] ?? '')),
                'mcq_question' => (string) ($item['question_text'] ?? ''),
                'mcq_answer' => (string) ($item['correct_answer'] ?? ''),
                'reason' => $isMentorMaths
                    ? 'Skipped — not transformed into a numeric MentorMaths blank'
                    : $this->conversion->notConvertibleReason($item),
            ];
        }

        return [
            'total' => count($items),
            'convertible_count' => count($convertible),
            'not_possible_count' => count($notPossible),
            'blocked_count' => count($blocked),
            'convertible' => $convertible,
            'not_possible' => $notPossible,
            'blocked' => $blocked,
            'is_mentormaths' => $isMentorMaths,
            'transform_required' => $isMentorMaths,
        ];
    }

    /**
     * @return array{
     *     convertible_count: int,
     *     not_possible_count: int,
     *     checked_count: int,
     *     total: int
     * }
     */
    public function apply(ContentUploadTask $task, string $json): array
    {
        return $this->conversion->applyGeminiJson($task, $json);
    }

    /**
     * @return array{convertible_count: int, not_possible_count: int, checked_count: int, total: int}
     */
    public function applyForChapter(TextbookChapter $chapter, string $json): array
    {
        return $this->conversion->applyGeminiJsonToChapter($chapter, $json);
    }
}
