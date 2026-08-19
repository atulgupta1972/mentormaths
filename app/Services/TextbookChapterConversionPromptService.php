<?php

namespace App\Services;

use App\Models\TextbookChapter;

class TextbookChapterConversionPromptService
{
    public function __construct(
        private TextbookSetCodeService $setCodes,
    ) {}

    /**
     * @return array{prompt: string, sample_json: string, mcq_reference_json: string, question_count: int, fill_blank_set_code: string, written_set_code: string}
     */
    public function payload(TextbookChapter $chapter): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);
        $items = $chapter->extraction_items ?? [];

        if ($items === []) {
            throw new \InvalidArgumentException('Import MCQs first before generating a fill-blank conversion prompt.');
        }

        $reference = $this->mcqReference($chapter, $items);
        $referenceJson = json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $codes = $this->setCodes->codes($chapter);
        $count = count($reference['questions']);
        $fillPlan = $this->setCodes->fillBlankPartPlan($chapter, $count);
        $writtenPlan = $this->setCodes->writtenPartPlan($chapter, $count);

        $context = $this->chapterContext($chapter);

        $prompt = <<<PROMPT
Convert the attached MCQ reference JSON into fill-in-the-blank questions for the same chapter.
Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Input:
- Attach or paste mcq_reference.json ({$count} MCQ items with correct answers).
- Keep source_index matching the MCQ row (1..{$count}). You may SKIP a row (omit it) when it must stay MCQ-only.

Conversion rules:
1. One blank per question, shown as "____" in the question text.
2. Prefer numeric / maths blanks:
   - whole number → answer_format "integer" (commas in 13,579 are fine; store 13579)
   - decimal with a required form → "decimal" plus decimal_places (e.g. 2 for 2.50)
   - fraction → "fraction" and store like 2/3 (equivalent 4/6 is accepted when students answer)
   - short algebra token (coefficient, 4xy, x^2) → "text"
3. SKIP (do not convert) long English / spelling answers: number names ("thirteen thousand…"), "which of the following is true", and other option-shaped items. Those stay MCQ only.
4. Algebra expansions: do not ask for the full expansion in one blank. Split into separate questions (coefficient of x², of xy, of y²) using extra source_index copies of the same MCQ is NOT allowed — pick one numeric/token blank, or skip.
5. Preserve topic names, tables, and diagram needs from the source MCQ.
6. Explanation must end with the same value as correct_answer.
7. Do NOT include options arrays.

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

        $sample = [
            'questions' => [
                [
                    'source_index' => 1,
                    'topic' => 'Mean',
                    'question' => 'Runs 67, 55, 18 and 35 — the total is ____.',
                    'answer_format' => 'integer',
                    'correct_answer' => '175',
                    'method_hint' => 'Add all values.',
                    'explanation' => '67+55+18+35 = 175.',
                    'difficulty' => 'Easy',
                ],
            ],
        ];

        return [
            'prompt' => $prompt,
            'sample_json' => json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'mcq_reference_json' => $referenceJson,
            'question_count' => $count,
            'fill_blank_set_code' => $fillPlan[0]['set_code'] ?? $codes['fill_blank'].'1',
            'fill_blank_set_codes' => array_column($fillPlan, 'set_code'),
            'written_set_code' => $writtenPlan[0]['set_code'] ?? $codes['written'].'1',
            'written_set_codes' => array_column($writtenPlan, 'set_code'),
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
            $options = collect($item['mcq_options'] ?? [])
                ->pluck('text')
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

        return [
            'chapter' => "Ch {$chapter->chapter_number} — {$chapter->title}",
            'book' => $chapter->textbook?->name,
            'grade' => $chapter->textbook?->gradeLevel?->name,
            'questions' => $questions,
        ];
    }

    private function chapterContext(TextbookChapter $chapter): string
    {
        return collect([
            $chapter->textbook?->gradeLevel?->name ? "Class: {$chapter->textbook->gradeLevel->name}" : null,
            $chapter->textbook?->name ? "Book: {$chapter->textbook->name}" : null,
            "Chapter {$chapter->chapter_number}: {$chapter->title}",
            $chapter->syllabusChapter?->name ? "Syllabus: {$chapter->syllabusChapter->name}" : null,
        ])->filter()->implode("\n");
    }
}
