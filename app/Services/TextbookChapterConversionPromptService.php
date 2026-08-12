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

        $context = $this->chapterContext($chapter);
        $count = count($reference['questions']);

        $prompt = <<<PROMPT
Convert the attached MCQ reference JSON into fill-in-the-blank questions for the same chapter.
Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Input:
- Attach or paste mcq_reference.json ({$count} MCQ items with correct answers).
- Keep the SAME order and count — one fill-blank question per MCQ row (use "source_index" 1..{$count}).

Conversion rules:
1. Turn each MCQ into ONE blank shown as "____" in the question text.
2. When the MCQ correct answer is numeric, the blank answer must be that number (revise the question stem so the blank is the numeric result).
3. When the MCQ is conceptual with a text correct option, use answer_format "text" and a short blank phrase.
4. Preserve topic names, tables, and diagram needs from the source MCQ.
5. Re-use method_hint / explanation from the MCQ when still valid; fix so the final value in explanation equals correct_answer exactly.
6. Do NOT include options arrays — fill-in-the-blank only.
7. For diagram questions, keep needs_diagram true and the same diagram_file name if present.

Set codes after publish: online fill-blank {$codes['fill_blank']}, written sheet {$codes['written']}.

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
            'fill_blank_set_code' => $codes['fill_blank'],
            'written_set_code' => $codes['written'],
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
