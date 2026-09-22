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
                    ? 'Skipped by Gemini — use Invent numeric pack below'
                    : $this->conversion->notConvertibleReason($item),
            ];
        }

        $rewritePack = $this->blockedRewritePack($chapter, $blocked);
        $skippedPack = $isMentorMaths
            ? $this->skippedRewritePack($chapter, $notPossible)
            : ['prompt' => '', 'reference_json' => ''];

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
            'skipped_rewrite_prompt' => $skippedPack['prompt'],
            'skipped_rewrite_reference_json' => $skippedPack['reference_json'],
        ];
    }

    /**
     * Ready fill-blanks that would still fail publish (similarity / brand wording).
     *
     * @return list<array<string, mixed>>
     */
    public function publishBlockers(TextbookChapter $chapter): array
    {
        $chapter->loadMissing('textbook');

        if (! ($chapter->textbook?->isMentorMathsPracticeLine() ?? false)) {
            return [];
        }

        $items = array_values(array_filter(
            is_array($chapter->extraction_items) ? $chapter->extraction_items : [],
            fn ($item) => is_array($item),
        ));

        $blockers = [];

        foreach ($items as $index => $item) {
            $hasBlank = filled($item['fill_blank_question_text'] ?? null)
                && filled($item['fill_blank_correct_answer'] ?? null)
                && empty($item['fill_blank_skipped']);

            if (! $hasBlank) {
                continue;
            }

            $sourceStem = (string) ($item['question_text'] ?? '');
            $fillStem = (string) ($item['fill_blank_question_text'] ?? '');
            $result = $this->similarity->compare($sourceStem, $fillStem);

            if (! $result['too_similar']) {
                continue;
            }

            $blockers[] = [
                'index' => $index,
                'number' => $index + 1,
                'label' => trim((string) ($item['label'] ?? $item['topic'] ?? '')),
                'mcq_question' => $sourceStem,
                'fill_blank_question' => $fillStem,
                'correct_answer' => (string) ($item['fill_blank_correct_answer'] ?? ''),
                'answer_format' => (string) ($item['fill_blank_answer_format'] ?? ''),
                'explanation' => (string) ($item['fill_blank_explanation'] ?? ''),
                'method_hint' => (string) ($item['fill_blank_method_hint'] ?? ''),
                'topic' => $item['topic'] ?? $item['label'] ?? null,
                'difficulty' => $item['difficulty'] ?? null,
                'reason' => $result['reason'] ?? 'Stem too close to source',
                'overlap' => $result['overlap'],
            ];
        }

        return $blockers;
    }

    /**
     * @return array{prompt: string, reference_json: string, blocker_count: int, blockers: list<array<string, mixed>>}
     */
    public function publishBlockerRewritePack(TextbookChapter $chapter): array
    {
        $blockers = $this->publishBlockers($chapter);
        $pack = $this->blockedRewritePack($chapter, $blockers);

        return [
            'prompt' => $pack['prompt'],
            'reference_json' => $pack['reference_json'],
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
        ];
    }

    /**
     * Rescue pack for rows Gemini omitted (proof/theory/etc.) — invent numeric blanks.
     *
     * @return array{prompt: string, reference_json: string, remaining_count: int}
     */
    public function remainingRewritePack(TextbookChapter $chapter): array
    {
        $chapter->loadMissing('textbook');
        $items = array_values(array_filter(
            is_array($chapter->extraction_items) ? $chapter->extraction_items : [],
            fn ($item) => is_array($item),
        ));

        $remaining = [];
        foreach ($items as $index => $item) {
            $hasBlank = filled($item['fill_blank_question_text'] ?? null)
                && filled($item['fill_blank_correct_answer'] ?? null)
                && empty($item['fill_blank_skipped']);

            if ($hasBlank) {
                continue;
            }

            $remaining[] = [
                'index' => $index,
                'number' => $index + 1,
                'label' => trim((string) ($item['label'] ?? $item['topic'] ?? '')),
                'mcq_question' => (string) ($item['question_text'] ?? ''),
                'mcq_answer' => (string) ($item['correct_answer'] ?? ''),
                'topic' => $item['topic'] ?? $item['label'] ?? null,
                'difficulty' => $item['difficulty'] ?? null,
                'reason' => 'Still needs a numeric MentorMaths blank',
            ];
        }

        $pack = $this->skippedRewritePack($chapter, $remaining);

        return [
            'prompt' => $pack['prompt'],
            'reference_json' => $pack['reference_json'],
            'remaining_count' => count($remaining),
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
     * @param  list<array<string, mixed>>  $skipped
     * @return array{prompt: string, reference_json: string}
     */
    public function skippedRewritePack(TextbookChapter $chapter, array $skipped): array
    {
        if ($skipped === []) {
            return ['prompt' => '', 'reference_json' => ''];
        }

        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);
        $count = count($skipped);

        $questions = [];
        foreach ($skipped as $row) {
            $questions[] = array_filter([
                'source_index' => (int) ($row['number'] ?? 0),
                'topic' => $row['topic'] ?? $row['label'] ?? null,
                'source_question' => $row['mcq_question'] ?? null,
                'source_answer' => $row['mcq_answer'] ?? null,
                'skip_reason' => $row['reason'] ?? null,
                'difficulty' => $row['difficulty'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');
        }

        $reference = [
            'chapter' => "Ch {$chapter->chapter_number} — {$chapter->title}",
            'book' => $chapter->textbook?->name,
            'grade' => $chapter->textbook?->gradeLevel?->name,
            'skipped_count' => $count,
            'questions' => $questions,
        ];

        $context = collect([
            $chapter->textbook?->gradeLevel?->name ? "Class: {$chapter->textbook->gradeLevel->name}" : null,
            $chapter->textbook?->name ? "Book: {$chapter->textbook->name} (MentorMaths invent-numeric pass)" : null,
            "Chapter {$chapter->chapter_number}: {$chapter->title}",
        ])->filter()->implode("\n");

        $prompt = <<<PROMPT
These {$count} MentorMaths source rows were SKIPPED (proof / theory / criterion / non-numeric).
Invent ORIGINAL numeric fill-in-the-blank questions on the SAME skill for each source_index.
Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Input:
- Attach/paste skipped_reference.json ({$count} omitted rows).
- Keep the same source_index values exactly.
- You MUST attempt every source_index. Aim to return all {$count} rows.

Invent rules (mandatory):
1. Every question MUST contain exactly one blank shown as "____" (four underscores).
2. Completely original MentorMaths wording — never copy the source stem.
3. Invent concrete numbers (lengths, angles, counts) so the student computes a numeric answer.
4. For congruence / CPCT / RHS / SAS / ASA / "which criterion" rows: give side lengths or angles and ask for a missing length, angle, or perimeter segment — do NOT ask which criterion name.
5. Never mention publisher brands, book titles, exercise codes, or page numbers.
6. "correct_answer" must be integer / decimal / simple fraction only (e.g. "42", "3/4"). No words, true/false, option letters.
7. Put units in the stem ("____ cm"), not in correct_answer.
8. answer_format only: "integer", "decimal", or "fraction".
9. explanation must end with the same value as correct_answer.
10. Skip a source_index ONLY if that skill cannot support any honest numeric blank.

JSON format:
{
  "questions": [
    {
      "source_index": 49,
      "topic": "RHS congruence",
      "question": "In right △ABC with right angle at C, AC = 9 cm and BC = 12 cm. Hypotenuse AB is ____ cm.",
      "answer_format": "integer",
      "correct_answer": "15",
      "method_hint": "Use the Pythagoras theorem.",
      "explanation": "AB² = 9² + 12² = 81 + 144 = 225, so AB = 15.",
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
