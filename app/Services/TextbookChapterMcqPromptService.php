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
        $age = $chapter->textbook?->gradeLevel?->typicalAge();
        $ageLine = $age ? "\n- Typical student age: {$age} years" : '';
        $book = $chapter->textbook?->name ?? 'Textbook';
        $bookCode = strtoupper($chapter->textbook?->code ?? 'TB');
        $chapterNum = $chapter->chapter_number;
        $title = $chapter->title;
        $codes = $this->setCodes->codes($chapter);

        $sample = [
            'questions' => [
                [
                    'topic' => 'Explicit rule',
                    'question' => 'Find the 5th term of the sequence tₙ = 3n − 4 (n ≥ 1).',
                    'options' => ['8', '11', '14', '17'],
                    'correct_index' => 1,
                    'hint' => 'Substitute n = 5 into the explicit formula.',
                    'explanation' => 't5 = 3(5) − 4 = 11. Answer: B',
                    'difficulty' => 'Easy',
                ],
                [
                    'topic' => 'Reading a table',
                    'question' => 'The table shows the number of books read by four students in March. Who read the most books?',
                    'table' => [
                        'headers' => ['Student', 'Books read'],
                        'rows' => [
                            ['Anya', '5'],
                            ['Bhuvan', '8'],
                            ['Chitra', '6'],
                            ['Dev', '4'],
                        ],
                    ],
                    'options' => ['Anya', 'Bhuvan', 'Chitra', 'Dev'],
                    'correct_index' => 1,
                    'hint' => 'Compare the values in the second column.',
                    'explanation' => 'Bhuvan read 8 books, which is the greatest. Answer: B',
                    'difficulty' => 'Easy',
                ],
            ],
        ];

        $sampleJson = json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Extract every gradable maths MCQ from this textbook chapter PDF.

Context:
- Class: {$grade}{$ageLine}
- Book: {$book} (code {$bookCode})
- Chapter {$chapterNum}: {$title}

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
      "chart": "optional — full chart/graph description when the PDF uses a figure",
      "table": "optional — markdown table string OR {\"headers\": [...], \"rows\": [[...]]}",
      "options": ["A text", "B text", "C text", "D text"],
      "correct_index": 0,
      "hint": "One-line method hint",
      "explanation": "Brief working + Answer: A/B/C/D",
      "difficulty": "Easy|Medium|Hard"
    }
  ]
}

Rules:
- Return questions only — do NOT include set_plan or grouping metadata
- correct_index is 0-based (0 = first option)
- Exactly 4 options per question when possible
- Do not skip numbered exercises — extract ALL solvable items
- Fix broken subscripts (t_n, u_n) in question text
- For diagram/geometry questions, describe the figure fully in "question" and/or "chart"

Charts and tables:
- For **zip import with images** (recommended for bar graphs, dot plots, geometry): put PNG/JPG files in the zip and set `"diagram_file": "chart1.png"` (or `"chart_file"`) on each question. Optional `"chart"` / `"table"` text is still merged into the question as backup.
- For **paste JSON only** (no images): flatten ALL data into `"chart"` and/or `"table"`. Example:
  "Bar chart 'Books sold' (y-axis: number of books, 1 unit = 10 books). Jan: 30, Feb: 50, Mar: 40."
  Do NOT put a grid inside "chart" — use sentences or comma-separated label: value pairs.
  Include title, axis labels, scale/units, and every category value. Never say "see graph above".
- Tables: use structured {"headers": [...], "rows": [[...]]} or a simple markdown table string in "table".
  Include every column header and row with exact numbers. Never say "see the table above".
- Each question must be fully solvable from JSON alone when no diagram_file is used.
- Double-check table/chart numbers against the PDF; do not round or omit rows.

Zip pack format (charts / pictures):
- Zip contains `questions.json` plus image files (`chart1.png`, `q3.jpg`, …).
- In JSON, set `"diagram_file": "chart1.png"` on questions that need that figure.
- Multiple questions may share one image file. Filename matching is case-insensitive.
- Upload the zip on the textbook chapter page (Step 3 — Import zip pack).

After import, the admin splits questions into class sets on the review page.

Sample:
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
