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
                    'topic' => 'Integers on a number line',
                    'question' => 'Madhre stands 20 m above the river; the river is 35 m deep below the bridge. The vertical distance from her foot to the river bottom is ____ metres.',
                    'answer_unit' => 'm',
                    'options' => ['45', '55', '35', '20', '75', '40', '25', '65'],
                    'correct_index' => 1,
                    'hint' => 'Add height above water and depth below water.',
                    'explanation' => '20 + 35 = 55 metres.',
                    'difficulty' => 'Medium',
                    'needs_diagram' => true,
                    'diagram_file' => 'chart1.png',
                    'chart' => 'THIS QUESTION REQUIRES A FIGURE UPLOAD — bridge 20 m above water, river 35 m deep.',
                ],
                [
                    'topic' => 'Integer multiplication',
                    'question' => '[(–10) × (+9)] + (–10) = ____',
                    'options' => ['100', '–100', '–80', '80', '–90', '90', '–110', '110'],
                    'correct_index' => 1,
                    'hint' => 'Multiply first, then add.',
                    'explanation' => '[(–10)×9]+(–10)=–90+(–10)=–100.',
                    'difficulty' => 'Medium',
                    'needs_diagram' => false,
                ],
                [
                    'topic' => 'Commutative property',
                    'question' => '(–25) × 30 = –30 × ____',
                    'options' => ['–25', '30', '–30', '25', '0', '750', '-750', '1'],
                    'correct_index' => 3,
                    'hint' => 'a×b = b×a for integers.',
                    'explanation' => '(–25)×30 = –30×25.',
                    'difficulty' => 'Hard',
                    'needs_diagram' => false,
                ],
            ],
        ];

        $sampleJson = json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $varyRule = McqGenerationPrompt::VARY_CORRECT_OPTION_RULE;
        $eightOptionRule = McqGenerationPrompt::EIGHT_OPTION_RULE;
        $fillBlankFirstRule = McqGenerationPrompt::FILL_BLANK_FIRST_RULE;
        $difficultyRule = McqGenerationPrompt::EXEMPLAR_DIFFICULTY_RULE;
        $excludeRule = McqGenerationPrompt::EXEMPLAR_EXCLUDE_RULE;

        $prompt = <<<PROMPT
GOAL: 100% content coverage for this chapter PDF (NCERT Exemplar / textbook). Convert every solvable maths item — do not stop early, do not sample, do not summarise.

Every solvable item must become one question — including worked examples. Missing even one example or exercise is a failed extraction.

Context:
- Class: {$grade}{$ageLine}
- Book: {$book} (code {$bookCode})
- Chapter {$chapterNum}: {$title}

{$fillBlankFirstRule}

{$difficultyRule}

MIXED SET (after import):
- Bare numbers, decimals, and simple fractions auto-convert to fill-in-blank on import.
- Still provide 8 options in JSON for every row (used for validation); numeric correct options must NOT include units.

MUST INCLUDE (do not skip):
- Worked examples — each becomes a solvable question (Medium or Hard)
- Try these / Let's do / Do this / Practice / Exercise items
- Every numbered question and sub-part (1(a), 1(b), … — each part is its own question)
- Starred (*) / challenge questions — mark difficulty "Hard"
- Questions with figures, graphs, tables — include them (flag needs_diagram)

{$excludeRule}

EXCLUDE only:
- "Think and Reflect" with no single maths answer
- Theory-only paragraphs with no student calculation
- Blank graph-paper pages with no printed question

Return ONLY valid JSON (no markdown fences):

{
  "questions": [
    {
      "topic": "Short topic label",
      "question": "Student-facing text with ____ for numeric answers; state units in the question, not in options",
      "answer_unit": "optional — m, cm, kg, °C, %, …",
      "needs_diagram": true,
      "diagram_file": "chart1.png",
      "chart": "optional — full chart/graph description when the PDF uses a figure",
      "table": "optional — markdown table string OR {\"headers\": [...], \"rows\": [[...]]}",
      "options": ["bare numbers or words", "…", "…", "…", "…", "…", "…", "…"],
      "correct_index": 2,
      "hint": "One-line method hint",
      "explanation": "Brief working + final value",
      "difficulty": "Medium|Hard"
    }
  ]
}

Rules:
- Return questions only — do NOT include set_plan or grouping metadata
- correct_index is 0-based (0 = A, 1 = B, … 7 = H)
{$varyRule}
{$eightOptionRule}
- Prefer Medium/Hard; use Easy only for the simplest introductory example if the PDF has nothing harder
- One PDF item = one question. Split multi-part questions (a)(b)(c) into separate questions
- Fix broken subscripts (t_n, u_n) in question text

FIGURE / DIAGRAM FLAG:
- If the PDF question depends on a figure, graph, chart, map, geometry drawing, or photo, set:
  `"needs_diagram": true`
  and start `"chart"` with exactly: `THIS QUESTION REQUIRES A FIGURE UPLOAD —`
  then describe the figure briefly.
- Also set `"diagram_file": "chart1.png"` (unique filename) when packing a zip with that image.
- If the question is text-only (or a table fully in JSON), set `"needs_diagram": false` and omit diagram_file.

Charts and tables:
- For **zip import with images** (recommended): put PNG/JPG files in the zip and set `"diagram_file"` plus `"needs_diagram": true`.
- For **paste JSON only**: still set `"needs_diagram": true` and the REQUIRED FIGURE UPLOAD sentence in `"chart"`.
- Tables: use structured {"headers": [...], "rows": [[...]]} or markdown table string.
- Each question must be fully solvable from JSON alone when no diagram_file is used (or after the figure is uploaded).

Zip pack format:
- Zip contains `questions.json` plus image files. Upload on Step 3.

After import, the uploader reviews each question, uploads missing figures, and can click **→ Fill blank** on any MCQ row that should be numeric fill-in-blank.

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
