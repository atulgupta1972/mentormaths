<?php

namespace App\Services;

use App\Models\TextbookChapter;
use App\Support\McqGenerationPrompt;

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
                    'topic' => 'Addition',
                    'question' => 'Find the sum of 47 and 38.',
                    'options' => ['75', '80', '85', '90', '95', '100', '105', '85'],
                    'correct_index' => 2,
                    'hint' => 'Add the ones, then the tens.',
                    'explanation' => '47 + 38 = 85. Answer: C',
                    'difficulty' => 'Easy',
                    'needs_diagram' => false,
                ],
                [
                    'topic' => 'Fractions',
                    'question' => 'What is 2/3 of 15?',
                    'options' => ['8', '9', '10', '11', '12', '13', '14', '15'],
                    'correct_index' => 2,
                    'hint' => 'Multiply 15 by 2/3.',
                    'explanation' => '15 × 2/3 = 10. Answer: C',
                    'difficulty' => 'Easy',
                    'needs_diagram' => false,
                ],
                [
                    'topic' => 'Reading a bar graph',
                    'question' => 'The bar graph shows books sold each month. In which month were the most books sold?',
                    'needs_diagram' => true,
                    'diagram_file' => 'chart1.png',
                    'chart' => 'THIS QUESTION REQUIRES A FIGURE UPLOAD — bar graph of books sold by month.',
                    'options' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August'],
                    'correct_index' => 1,
                    'hint' => 'Compare the heights of the bars.',
                    'explanation' => 'February has the tallest bar. Answer: B',
                    'difficulty' => 'Easy',
                ],
            ],
        ];

        $sampleJson = json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $varyRule = McqGenerationPrompt::VARY_CORRECT_OPTION_RULE;
        $eightOptionRule = McqGenerationPrompt::EIGHT_OPTION_RULE;

        $prompt = <<<PROMPT
GOAL: 100% content coverage. Convert this entire textbook chapter PDF into a mixed practice set. Do not stop early. Do not summarise. Do not sample.

Every solvable maths item in the PDF must become one question — including worked examples. Missing even one example or exercise is a failed extraction.

Context:
- Class: {$grade}{$ageLine}
- Book: {$book} (code {$bookCode})
- Chapter {$chapterNum}: {$title}

MIXED SET RULES (important):
- If the correct answer is a **whole number**, **decimal** (e.g. 3.14, 2.50), or **simple fraction** (e.g. 2/3, -5/8), the system will auto-convert it to **fill in the blank** after import.
- All other answers (names, words, True/False, mixed fractions like 2 1/3, expressions) stay as **MCQ with exactly 8 options** (A–H).
- Still provide 8 options in JSON for every question — fill-blank items use them only for validation; numeric answers become fill-blank automatically.

MUST INCLUDE (do not skip any of these):
- Worked examples — turn each into a solvable question with the same calculation/result
- Try these / Let's do / Do this / Check your progress / Practice items
- Every numbered question and sub-part (1(a), 1(b), … — each part is its own question)
- Starred (*) / challenge questions — mark difficulty "Hard"
- Questions with figures, graphs, tables — still include them (flag needs_diagram)

EXCLUDE only:
- "Think and Reflect" with no single maths answer
- Theory-only paragraphs with no student calculation
- Blank graph-paper pages with no printed question

Return ONLY valid JSON (no markdown fences) in this exact shape:

{
  "questions": [
    {
      "topic": "Short topic label",
      "question": "Student-facing question text",
      "needs_diagram": true,
      "diagram_file": "chart1.png",
      "chart": "optional — full chart/graph description when the PDF uses a figure",
      "table": "optional — markdown table string OR {\"headers\": [...], \"rows\": [[...]]}",
      "options": ["A", "B", "C", "D", "E", "F", "G", "H"],
      "correct_index": 2,
      "hint": "One-line method hint",
      "explanation": "Brief working + Answer: C",
      "difficulty": "Easy|Medium|Hard"
    }
  ]
}

Rules:
- Return questions only — do NOT include set_plan or grouping metadata
- correct_index is 0-based (0 = A, 1 = B, … 7 = H)
{$varyRule}
{$eightOptionRule}
- Do not skip examples, try-these, or numbered exercises — extract ALL solvable items for 100% coverage
- One PDF item = one question. Split multi-part questions (a)(b)(c) into separate questions
- Fix broken subscripts (t_n, u_n) in question text

FIGURE / DIAGRAM FLAG (important for uploaders):
- If the PDF question depends on a figure, graph, chart, map, geometry drawing, or photo, set:
  `"needs_diagram": true`
  and start `"chart"` with exactly: `THIS QUESTION REQUIRES A FIGURE UPLOAD —`
  then describe the figure briefly.
- Also set `"diagram_file": "chart1.png"` (unique filename) when packing a zip with that image.
- If the question is text-only (or a table fully in JSON), set `"needs_diagram": false` and omit diagram_file.
- Uploaders use `needs_diagram: true` to know they must upload the correct figure while reviewing.

Charts and tables:
- For **zip import with images** (recommended): put PNG/JPG files in the zip and set `"diagram_file"` plus `"needs_diagram": true`.
- For **paste JSON only**: still set `"needs_diagram": true` and the REQUIRED FIGURE UPLOAD sentence in `"chart"`.
- Tables: use structured {"headers": [...], "rows": [[...]]} or markdown table string.
- Each question must be fully solvable from JSON alone when no diagram_file is used (or after the figure is uploaded).

Zip pack format:
- Zip contains `questions.json` plus image files. Upload on Step 3.

After import, the uploader reviews each question, uploads missing figures, and runs Gemini answer + figure check before admin publishes.

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
