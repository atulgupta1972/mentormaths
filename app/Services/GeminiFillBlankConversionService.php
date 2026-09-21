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

            $answer = trim((string) ($row['correct_answer'] ?? ''));
            $fillBlankQuestion = FillBlankStem::ensureBlank(
                (string) ($row['question_text'] ?? ''),
                $answer,
            );
            $sourceStem = (string) ($items[$itemIndex]['question_text'] ?? '');
            $answerFormat = (string) ($row['answer_format'] ?? '');
            $explanation = (string) ($row['explanation'] ?? '');
            $methodHint = (string) ($row['method_hint'] ?? '');
            $topic = trim((string) ($row['topic'] ?? $items[$itemIndex]['topic'] ?? $items[$itemIndex]['label'] ?? ''));
            $difficulty = (string) ($row['difficulty'] ?? $items[$itemIndex]['difficulty'] ?? '');

            $blockReason = null;
            $overlap = null;

            if (! FillBlankStem::hasBlank($fillBlankQuestion)) {
                $blockReason = 'Missing ____ blank in the question stem.';
            } else {
                $similarityResult = $this->similarity->compare($sourceStem, $fillBlankQuestion);
                $overlap = $similarityResult['overlap'];

                if ($isMentorMaths && $similarityResult['too_similar']) {
                    $blockReason = $similarityResult['reason'];
                }
            }

            if ($blockReason !== null) {
                $blockedIndexes[] = $itemIndex;
                $blocked[] = [
                    'index' => $itemIndex,
                    'number' => $sourceIndex,
                    'label' => trim((string) ($items[$itemIndex]['label'] ?? $items[$itemIndex]['topic'] ?? '')),
                    'mcq_question' => $sourceStem,
                    'fill_blank_question' => $fillBlankQuestion,
                    'correct_answer' => $answer,
                    'answer_format' => $answerFormat,
                    'explanation' => $explanation,
                    'method_hint' => $methodHint,
                    'topic' => $topic,
                    'difficulty' => $difficulty,
                    'reason' => $blockReason,
                    'overlap' => $overlap,
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
                'correct_answer' => $answer,
                'answer_format' => $answerFormat,
                'similarity_overlap' => $overlap,
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

        $rewritePack = $this->blockedRewritePack($chapter, $blocked);

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
            'rewrite_prompt' => $rewritePack['prompt'],
            'rewrite_reference_json' => $rewritePack['reference_json'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocked
     * @return array{prompt: string, reference_json: string}
     */
    public function blockedRewritePack(TextbookChapter $chapter, array $blocked): array
    {
        if ($blocked === []) {
            return ['prompt' => '', 'reference_json' => ''];
        }

        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);
        $count = count($blocked);

        $questions = [];
        foreach ($blocked as $row) {
            $questions[] = array_filter([
                'source_index' => (int) ($row['number'] ?? 0),
                'topic' => $row['topic'] ?? $row['label'] ?? null,
                'source_question' => $row['mcq_question'] ?? null,
                'failed_fill_blank_question' => $row['fill_blank_question'] ?? null,
                'block_reason' => $row['reason'] ?? null,
                'correct_answer_hint' => $row['correct_answer'] ?? null,
                'answer_format' => $row['answer_format'] ?? null,
                'explanation_hint' => $row['explanation'] ?? null,
                'method_hint' => $row['method_hint'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        $reference = [
            'chapter' => "Ch {$chapter->chapter_number} — {$chapter->title}",
            'book' => $chapter->textbook?->name,
            'grade' => $chapter->textbook?->gradeLevel?->name,
            'blocked_count' => $count,
            'questions' => $questions,
        ];

        $context = collect([
            $chapter->textbook?->gradeLevel?->name ? "Class: {$chapter->textbook->gradeLevel->name}" : null,
            $chapter->textbook?->name ? "Book: {$chapter->textbook->name} (MentorMaths rewrite pass)" : null,
            "Chapter {$chapter->chapter_number}: {$chapter->title}",
        ])->filter()->implode("\n");

        $prompt = <<<PROMPT
Rewrite ONLY these {$count} blocked MentorMaths fill-in-the-blank questions.
Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Input:
- Attach/paste blocked_reference.json ({$count} rows that failed preview).
- Keep the same source_index values exactly.

Fix rules (mandatory):
1. Every question MUST contain exactly one blank shown as "____" (four underscores). Never omit the blank.
2. Completely rewrite wording — do not lightly paraphrase the source or the failed stem.
3. Change every significant number/quantity so the answer changes when needed.
4. Replace person/place names with neutral Indian-generic names (or drop the story).
5. Never mention publisher brands, book titles, exercise codes, or page numbers.
6. "correct_answer" must be integer / decimal / simple fraction only (e.g. "42", "3/4"). No words, mixed fractions, true/false.
7. Put units in the stem ("____ metres"), not in correct_answer.
8. answer_format only: "integer", "decimal", or "fraction".
9. explanation must end with the same value as correct_answer.
10. Return ALL {$count} source_index rows if possible. Skip a row only if it cannot become a numeric blank.

JSON format:
{
  "questions": [
    {
      "source_index": 14,
      "topic": "Fractions",
      "question": "Find the missing factor: (5/6) × ____ = 25/54.",
      "answer_format": "fraction",
      "correct_answer": "5/9",
      "method_hint": "Divide the product by the known factor.",
      "explanation": "(25/54) ÷ (5/6) = 5/9.",
      "difficulty": "Easy",
      "needs_diagram": false
    }
  ]
}
PROMPT;

        return [
            'prompt' => $prompt,
            'reference_json' => json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}',
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
