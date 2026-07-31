<?php

namespace App\Services;

use App\Models\TextbookChapter;

class TextbookChapterMcqPromptService
{
    public function __construct(
        private TextbookSetCodeService $setCodes,
    ) {}

    /**
     * @return array{prompt: string, sample_json: string, mcq_set_code: string, written_set_code: string}
     */
    public function payload(TextbookChapter $chapter): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);

        $grade = $chapter->textbook?->gradeLevel?->name ?? 'Class';
        $book = $chapter->textbook?->name ?? 'Textbook';
        $bookCode = strtoupper($chapter->textbook?->code ?? 'TB');
        $chapterNum = $chapter->chapter_number;
        $title = $chapter->title;
        $codes = $this->setCodes->codes($chapter);

        $sample = [
            'questions' => [
                [
                    'topic' => 'Textbook',
                    'question' => 'Find the 5th term of the sequence tₙ = 3n − 4 (n ≥ 1).',
                    'options' => ['8', '11', '14', '17'],
                    'correct_index' => 1,
                    'hint' => 'Substitute n = 5 into the explicit formula.',
                    'explanation' => 't5 = 3(5) − 4 = 11. Answer: B',
                    'difficulty' => 'Easy',
                ],
            ],
        ];

        $sampleJson = json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Extract every gradable maths MCQ from this textbook chapter PDF.

Context:
- Class: {$grade}
- Book: {$book} (code {$bookCode})
- Chapter {$chapterNum}: {$title}
- Published set code will be: {$codes['mcq']}

INCLUDE:
- Worked examples ("Example 1", "Example 2", …)
- Inline "Exercise:" prompts in the chapter body
- Numbered questions in Exercise sets and End-of-Chapter exercises
- Starred (*) hard questions — mark difficulty "Hard"

EXCLUDE:
- "Think and Reflect" discussion prompts
- Theory-only paragraphs with no student answer
- Graph-paper pages

Return ONLY valid JSON (no markdown fences) in this exact shape:

{
  "questions": [
    {
      "topic": "Short topic label (e.g. Explicit rule, AP, End-of-chapter)",
      "question": "Student-facing question text",
      "options": ["A text", "B text", "C text", "D text"],
      "correct_index": 0,
      "hint": "One-line method hint",
      "explanation": "Brief working + Answer: A/B/C/D",
      "difficulty": "Easy|Medium|Hard"
    }
  ]
}

Rules:
- correct_index is 0-based (0 = first option)
- Exactly 4 options per question when possible
- Do not skip numbered exercises — extract ALL solvable items
- Fix broken subscripts (t_n, u_n) in question text
- For diagram questions, describe the figure in the question text

Sample (one question):
{$sampleJson}
PROMPT;

        return [
            'prompt' => $prompt,
            'sample_json' => $sampleJson,
            'mcq_set_code' => $codes['mcq'],
            'written_set_code' => $codes['written'],
        ];
    }
}
