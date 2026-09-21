<?php

namespace App\Services;

use App\Models\Textbook;
use App\Models\TextbookChapter;

class TextbookChapterConversionPromptService
{
    public function __construct(
        private TextbookSetCodeService $setCodes,
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
    public function payload(TextbookChapter $chapter, bool $includeMcqReferenceJson = false): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);
        $items = array_values(array_filter(
            is_array($chapter->extraction_items) ? $chapter->extraction_items : [],
            fn ($item) => is_array($item),
        ));

        if ($items === []) {
            throw new \InvalidArgumentException('Import MCQs first before generating a fill-blank conversion prompt.');
        }

        $reference = $this->mcqReference($chapter, $items);
        $count = count($reference['questions']);
        $codes = $this->setCodes->codes($chapter);
        $fillPlan = $this->setCodes->fillBlankPartPlan($chapter, $count);
        $writtenPlan = $this->setCodes->writtenPartPlan($chapter, $count);
        $isMentorMaths = $chapter->textbook?->isMentorMathsPracticeLine() ?? false;
        $context = $this->chapterContext($chapter, $isMentorMaths);

        $prompt = $isMentorMaths
            ? $this->mentorMathsTransformPrompt($context, $count, $codes)
            : $this->standardConversionPrompt($context, $count, $codes);

        $sample = [
            'questions' => [
                [
                    'source_index' => 1,
                    'topic' => 'Mean',
                    'question' => 'A batsman scored 72, 48, 21 and 39 runs. The mean score is ____.',
                    'answer_format' => 'integer',
                    'correct_answer' => '45',
                    'method_hint' => 'Add all values, then divide by how many there are.',
                    'explanation' => '72+48+21+39 = 180. Mean = 180÷4 = 45.',
                    'difficulty' => 'Easy',
                ],
            ],
        ];

        return [
            'prompt' => $prompt,
            'sample_json' => json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}',
            'mcq_reference_json' => $includeMcqReferenceJson
                ? (json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}')
                : '',
            'question_count' => $count,
            'fill_blank_set_code' => $fillPlan[0]['set_code'] ?? $codes['fill_blank'].'1',
            'fill_blank_set_codes' => array_column($fillPlan, 'set_code'),
            'written_set_code' => $writtenPlan[0]['set_code'] ?? $codes['written'].'1',
            'written_set_codes' => array_column($writtenPlan, 'set_code'),
            'practice_line' => $chapter->textbook?->practice_line ?? Textbook::PRACTICE_LINE_STANDARD,
            'is_mentormaths' => $isMentorMaths,
            'transform_required' => $isMentorMaths,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{chapter: string, book: string, grade: string, questions: list<array<string, mixed>>}
     */
    public function mcqReference(TextbookChapter $chapter, array $items): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);

        $questions = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $options = collect($item['mcq_options'] ?? [])
                ->map(function ($option) {
                    if (is_array($option)) {
                        return $option['text'] ?? null;
                    }

                    return is_string($option) ? $option : null;
                })
                ->filter()
                ->values()
                ->all();

            $questions[] = array_filter([
                'index' => $index + 1,
                'topic' => $item['topic'] ?? null,
                'question' => $item['question_text'] ?? null,
                'correct_answer' => $item['correct_answer'] ?? null,
                'options' => $options !== [] ? $options : null,
                'method_hint' => $item['method_hint'] ?? null,
                'explanation' => $item['explanation'] ?? null,
                'difficulty' => $item['difficulty'] ?? null,
                'table' => $item['table'] ?? null,
                'needs_diagram' => ! empty($item['needs_diagram']),
                'diagram_file' => $item['diagram_file'] ?? null,
            ], fn ($value) => $value !== null && $value !== false && $value !== []);
        }

        $bookLabel = $chapter->textbook?->isMentorMathsPracticeLine()
            ? ($chapter->textbook?->name ?? 'MentorMaths')
            : ($chapter->textbook?->name ?? 'Textbook');

        return [
            'chapter' => "Ch {$chapter->chapter_number} — {$chapter->title}",
            'book' => $bookLabel,
            'grade' => $chapter->textbook?->gradeLevel?->name,
            'questions' => $questions,
        ];
    }

    /**
     * @param  array{mcq: string, fill_blank: string, written: string}  $codes
     */
    private function mentorMathsTransformPrompt(string $context, int $count, array $codes): string
    {
        return <<<PROMPT
You are rewriting private source extracts into ORIGINAL MentorMaths fill-in-the-blank questions.
Return ONLY valid JSON (no markdown fences).

Context:
{$context}
Practice line: MentorMaths (fill-blank only — no MCQ publish for this book)

Input:
- Attach or paste source_reference.json ({$count} extracted items with answers). Treat these as PRIVATE working notes only — never copy wording.
- Keep source_index matching the source row (1..{$count}).
- Aim to return as many of the {$count} rows as possible. Chapters need at least 15 numeric fill-blanks.

TRANSFORM RULES (mandatory — copyright + anti-cheat):
1. Rewrite the question completely in fresh MentorMaths wording. Do NOT lightly paraphrase.
2. Change every significant number / quantity so the correct answer changes.
3. Replace person / place / school story names with neutral Indian-generic names (or drop the story wrapper).
4. NEVER mention publisher brands, book titles, exercise codes, or page numbers (no RD Sharma, RS Aggarwal, Grewal, Lakshmi, NCERT Exemplar, etc.).
5. One blank per question, shown as "____" in the question text.
6. "correct_answer" MUST be a whole number, decimal, or a simple proper/improper fraction only.
   Allowed examples: "42", "-7", "3.5", "3/4", "2/3"
   NEVER use English words, mixed fractions, true/false, yes/no, or option letters.
   NEVER put units in correct_answer — put the unit in the stem ("____ metres").
7. Allowed answer_format values ONLY: "integer", "decimal", "fraction" (never "text").
8. Algebra: do not ask for a full expansion in one blank. Pick one numeric blank (e.g. a coefficient).
9. Theory / proof / congruence / CPCT / "which criterion" / true-false style rows:
   Do NOT skip them. Invent a NEW numeric practice question on the SAME skill
   (give side lengths or angles, ask for a missing length/angle/count; apply a formula).
   Example: RHS congruence MCQ → "In △PQR, PQ = 8 cm, PR = 8 cm, and height from P is 6 cm. The base QR is ____ cm."
10. Preserve topic skill and diagram needs when still relevant after rewrite.
11. Explanation must end with the same value as correct_answer.
12. Do NOT include options arrays.
13. Skip (omit) a row ONLY if you truly cannot invent any honest numeric blank for that skill.

After publish, MentorMaths sets are fill-blank {$codes['fill_blank']}1 / {$codes['fill_blank']}2… and written {$codes['written']}1 / {$codes['written']}2… (no MCQ set).

JSON format:
{
  "questions": [
    {
      "source_index": 1,
      "topic": "Mean",
      "question": "A batsman scored 72, 48, 21 and 39 runs. The mean score is ____.",
      "answer_format": "integer",
      "correct_answer": "45",
      "method_hint": "Add all values, then divide by how many there are.",
      "explanation": "72+48+21+39 = 180. Mean = 180÷4 = 45.",
      "difficulty": "Easy",
      "needs_diagram": false
    }
  ]
}
PROMPT;
    }

    /**
     * @param  array{mcq: string, fill_blank: string, written: string}  $codes
     */
    private function standardConversionPrompt(string $context, int $count, array $codes): string
    {
        return <<<PROMPT
Convert the attached MCQ reference JSON into fill-in-the-blank questions for the same chapter.
Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Input:
- Attach or paste mcq_reference.json ({$count} MCQ items with correct answers).
- Keep source_index matching the MCQ row (1..{$count}). You may SKIP a row (omit it) when it must stay MCQ-only.

Answer rule (strict):
- "correct_answer" MUST be a whole number, decimal, or a simple proper/improper fraction only.
  Allowed examples: "42", "-7", "3.5", "13579", "3/4", "2/3"
- NEVER use English words (no "thirteen", "odd", "even", "true", "false", "greater than", option letters, or sentences).
- NEVER use mixed fractions (no "1 1/2", "2 3/4") — SKIP those rows; they stay MCQ only.
- NEVER use true/false or yes/no answers — SKIP those rows.
- NEVER include units in correct_answer — put the unit in the question stem ("____ metres") and store only the number.
- Allowed answer_format values ONLY: "integer", "decimal", "fraction" (never "text").

Conversion rules:
1. One blank per question, shown as "____" in the question text.
2. Formats:
   - whole number → "integer" (store 13579 not "thirteen thousand five hundred seventy-nine"; commas in input are fine)
   - decimal with a required form → "decimal" plus decimal_places (e.g. 2 for 2.50)
   - fraction → "fraction" and store like 3/4 (equivalent 6/8 is accepted when students answer)
3. If the MCQ answer is words / true-false / a mixed fraction / an option letter, SKIP the row (omit it from JSON). Those questions stay MCQ only in the same test set.
4. If the MCQ answer is a simple whole number or fraction like 3/4, convert directly — verify the blank answer matches the MCQ key.
5. Prefer to rewrite the question completely when the MCQ stem is awkward as a blank; keep the same numeric answer unless you also change the numbers consistently.
6. Algebra: do not ask for a full expansion in one blank. Pick one numeric blank (e.g. a coefficient) by rewriting, or skip.
7. Preserve topic names, tables, and diagram needs from the source MCQ when still relevant after rewrite.
8. Explanation must end with the same value as correct_answer.
9. Do NOT include options arrays.
10. Return ONLY rows you can convert. Omitted rows = MCQ-only in the published set.

After publish, book content is three matching parts: MCQ {$codes['mcq']} / {$codes['mcq']}2…, fill-blank {$codes['fill_blank']}1 / {$codes['fill_blank']}2…, written {$codes['written']}1 / {$codes['written']}2….

JSON format:
{
  "questions": [
    {
      "source_index": 1,
      "topic": "Mean",
      "question": "Runs 67, 55, 18 and 35 — the total is ____.",
      "answer_format": "integer",
      "correct_answer": "175",
      "method_hint": "Add all values.",
      "explanation": "67+55+18+35 = 175.",
      "difficulty": "Easy",
      "needs_diagram": false
    }
  ]
}
PROMPT;
    }

    private function chapterContext(TextbookChapter $chapter, bool $isMentorMaths): string
    {
        $bookLine = $chapter->textbook?->name
            ? ($isMentorMaths
                ? "Book: {$chapter->textbook->name} (MentorMaths practice line)"
                : "Book: {$chapter->textbook->name}")
            : null;

        return collect([
            $chapter->textbook?->gradeLevel?->name ? "Class: {$chapter->textbook->gradeLevel->name}" : null,
            $bookLine,
            "Chapter {$chapter->chapter_number}: {$chapter->title}",
            $chapter->syllabusChapter?->name ? "Syllabus: {$chapter->syllabusChapter->name}" : null,
        ])->filter()->implode("\n");
    }
}
