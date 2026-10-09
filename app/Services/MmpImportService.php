<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\SyllabusChapter;
use App\Models\Worksheet;
use App\Support\DiagramQuestionSupport;
use App\Support\FillBlankAnswerConsistency;
use App\Support\QuestionBankPurpose;
use App\Support\QuestionMethodHint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MmpImportService
{
    public function __construct(
        private FillBlankImportService $fillBlankImport,
        private PracticeSetService $practiceSets,
        private QuestionDiagramService $diagrams,
    ) {}

    /**
     * @param  array{
     *     total?: int,
     *     seed?: string,
     *     figure_notes?: string,
     *     draft_count?: int,
     *     draft_names?: list<string>
     * }  $options
     */
    public function cursorPrompt(SyllabusChapter $chapter, array $options = []): string
    {
        $chapter->loadMissing([
            'topics',
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
        ]);

        $version = $chapter->syllabusVersion;
        $total = max(5, min(16, (int) ($options['total'] ?? 8)));
        $seed = trim((string) ($options['seed'] ?? ''));
        $figureNotes = trim((string) ($options['figure_notes'] ?? ''));
        $draftCount = max(0, (int) ($options['draft_count'] ?? 0));
        /** @var list<string> $draftNames */
        $draftNames = array_values(array_filter(array_map(
            fn ($name) => trim((string) $name),
            is_array($options['draft_names'] ?? null) ? $options['draft_names'] : [],
        )));

        if ($seed === '' && $draftCount <= 0) {
            throw new InvalidArgumentException('Upload a rough draft photo/PDF, or paste a seed situation, before generating the MMP prompt.');
        }

        $context = collect([
            $version ? "Board: {$version->board->code}" : null,
            $version ? "Class: {$version->gradeLevel->name}" : null,
            $version ? "Typical student age: {$version->gradeLevel->typicalAge()} years" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            "Chapter: {$chapter->chapter_number} — {$chapter->name}",
            'Set type: Mentormaths Perfection (MMP) — exhaustive word-problem variants',
        ])->filter()->implode("\n");

        $topics = $chapter->topics->pluck('name')->filter()->values()->all();
        $topicLine = $topics !== []
            ? "\n- Prefer topics from this chapter when tagging: ".implode('; ', $topics)
            : '';

        $figureBlock = $figureNotes !== ''
            ? "\nFigure / diagram notes from the author:\n{$figureNotes}\n"
            : '';

        $draftBlock = '';
        $figureGenBlock = '';
        if ($draftCount > 0) {
            $nameLine = $draftNames !== []
                ? ' Files: '.implode(', ', $draftNames).'.'
                : '';
            $draftBlock = "\nRough draft / figure sketch photos attached by the author ({$draftCount} file".($draftCount === 1 ? '' : 's').").{$nameLine}\n"
                ."IMPORTANT — READ THE SKETCH(ES):\n"
                ."- Extract the full situation, givens, diagram labels, markings (equal sides, angles, parallel lines), and every question asked.\n"
                ."- That extracted seed becomes Q1 (rewritten as a clear self-contained sum).\n"
                ."- Then invent variants that keep the same geometry structure.\n";

            $figureGenBlock = <<<'FIG'

FIGURE GENERATION FROM THE AUTHOR'S SKETCH (required):
- The attached image(s) are ROUGH HAND-DRAWN sketches. Do NOT reuse the sketch as-is for students.
- GENERATE a clean, textbook-quality diagram image for EVERY question that needs a figure (usually all {$total} questions).
- Redraw the author's sketch: straight lines, clear vertex labels, standard CBSE markings (ticks for equal sides, arcs for equal angles, arrows for parallel lines). White background, high contrast, no handwriting, no photo noise.
- For Q1: redraw the seed sketch faithfully (same shape and labels; fix proportion/neatness only).
- For Q2–variants: redraw the SAME figure style with the variant's changed lengths/angles/labels so each sum has its own matching diagram.
- Save each generated diagram as a PNG named q1.png, q2.png, … matching question order.
- Also return JSON (below). The admin will upload your generated PNGs with the JSON.
- In every figure question set: "needs_diagram": true, "diagram_file": "qN.png", and "figure_spec" (short plain-English drawing brief: shapes, labels, equal marks, given lengths) so the figure can be regenerated if needed.
- Start figure stems with "In the figure, …"
FIG;
            $figureGenBlock = str_replace('{$total}', (string) $total, $figureGenBlock);
        } elseif (DiagramQuestionSupport::looksLikeGeometryChapter($chapter)) {
            $figureGenBlock = <<<'FIG'

FIGURES (geometry chapter):
- When a sum needs a diagram, set "needs_diagram": true, "diagram_file": "qN.png", and "figure_spec" (drawing brief).
- Prefer generating clean PNG figures when the author supplied a sketch; otherwise describe the figure fully in the stem.
FIG;
        }

        $seedBlock = $seed !== ''
            ? "Seed situation (typed notes — combine with any attached draft photos; this must become question 1 — rewritten as a complete self-contained sum):\n{$seed}\n"
            : "Seed situation: use the attached rough draft / figure sketch photo(s) as the only source for Q1 (rewrite clearly; do not leave facts only in the image).\n";

        return <<<PROMPT
Create Mentormaths Perfection (MMP) maths questions for an exhaustive practice set. Return ONLY valid JSON (no markdown fences). Also generate clean diagram PNG files when figure sketches were attached (see FIGURE GENERATION).

Context:
{$context}
{$draftBlock}
{$seedBlock}{$figureBlock}{$figureGenBlock}
Requirements:
- Exactly {$total} questions total (including the seed as Q1)
- Q1 MUST be the seed rewritten as one complete question (not a reference to "the figure above" without stating the facts)
- Questions 2–{$total} are variants of the same skill: change numbers, angles, labels, or a little of the setup, but keep Class-appropriate CBSE difficulty
- Prefer fill-in-the-blank: ONE blank shown as "____" in the question text
- Use answer formats "integer", "decimal", or "fraction" for fill-in-blank
- "correct_answer" must match the blank exactly (examples: "42", "-3.5", "3/4", "65")
- The final number in "explanation" MUST be identical to "correct_answer" (including sign) — end with Final answer = {{correct_answer}}
- Include "method_hint": theory/rules ONLY — no final numeric answer
- Include "explanation": full teacher-only working with the final answer
- Include "difficulty": Easy, Medium, or Hard (lean Hard for perfection)
- Include "topic" with a short topic name from the chapter when possible{$topicLine}
- Use type "fill_in_blank" by default
- Use type "mcq" ONLY when a numeric/fraction blank is impossible (e.g. true/false congruence statements). For MCQ include "options" (2–6 strings) and "correct_index" (0-based)
- Each stem must be self-contained (do not say "using the previous question" or "as above")
- Do NOT invent topics outside this chapter's skill

JSON format:
{
  "questions": [
    {
      "type": "fill_in_blank",
      "topic": "Congruence of triangles",
      "question": "In the figure, AH = 4 cm, BH = 3 cm, and △ABH ≅ △FEG. The length of FE is ____ cm.",
      "needs_diagram": true,
      "diagram_file": "q1.png",
      "figure_spec": "Two right triangles ABH and FEG; right angles at H and G; equal marks on AH=FG and BH=EG; label lengths AH=4, BH=3.",
      "answer_format": "integer",
      "correct_answer": "5",
      "method_hint": "Corresponding sides of congruent triangles are equal. Use Pythagoras on the right triangle when two legs are given.",
      "explanation": "AH and BH are legs of a right triangle, so AB = sqrt(16+9) = 5. Under congruence FE corresponds to AB, so FE = 5. Final answer = 5",
      "difficulty": "Hard"
    },
    {
      "type": "mcq",
      "topic": "Congruence of triangles",
      "question": "Given the markings in the figure, which statement is true?",
      "needs_diagram": true,
      "diagram_file": "q2.png",
      "figure_spec": "Same twin-triangle layout as q1 with congruence marks; no numeric lengths required.",
      "options": ["△ABH ≅ △FDG", "△ABL ≅ △FED", "△ACB ≅ △DEF", "None of these"],
      "correct_index": 0,
      "method_hint": "Match corresponding vertices from equal sides and equal angles marked on the figure.",
      "explanation": "Equal sides and included angle map A→F, B→D, H→G, so △ABH ≅ △FDG. Final answer = △ABH ≅ △FDG",
      "difficulty": "Hard"
    }
  ]
}
PROMPT;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseJson(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            throw new InvalidArgumentException('Paste the AI JSON first.');
        }

        if (preg_match('/^```(?:json)?\s*([\s\S]*?)```\s*$/i', $json, $match) === 1) {
            $json = trim($match[1]);
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('JSON is invalid. Paste only the questions object.');
        }

        $items = $decoded['questions'] ?? $decoded;
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('JSON must include a non-empty "questions" array.');
        }

        $rows = [];
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Question '.($index + 1).' must be an object.');
            }
            $rows[] = $this->normalizeItem($item, $index);
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, UploadedFile>  $diagramsByName  basename => file (e.g. q1.png)
     * @return array{worksheet: Worksheet, questions: list<Question>, diagram_count: int}
     */
    public function saveSet(
        SyllabusChapter $chapter,
        array $rows,
        int $userId,
        ?string $seedNotes = null,
        array $diagramsByName = [],
    ): array {
        if ($rows === []) {
            throw new InvalidArgumentException('Nothing to save — preview the JSON first.');
        }

        $diagramsByName = collect($diagramsByName)
            ->filter(fn ($file, $name) => $file instanceof UploadedFile && is_string($name) && $name !== '')
            ->mapWithKeys(fn (UploadedFile $file, string $name) => [strtolower(basename($name)) => $file])
            ->all();

        return DB::transaction(function () use ($chapter, $rows, $userId, $seedNotes, $diagramsByName) {
            $saved = [];
            $diagramCount = 0;

            foreach ($rows as $index => $row) {
                $topicId = $this->fillBlankImport->resolveTopicIdForChapterRow($chapter, $row);
                $type = ($row['type'] ?? Question::TYPE_FILL_IN_BLANK) === Question::TYPE_MCQ
                    ? Question::TYPE_MCQ
                    : Question::TYPE_FILL_IN_BLANK;

                $question = Question::create([
                    'syllabus_topic_id' => $topicId,
                    'type' => $type,
                    'question_text' => trim((string) $row['question_text']),
                    'explanation' => QuestionMethodHint::sanitizeExplanation($row['explanation'] ?? null),
                    'method_hint' => filled($row['method_hint'] ?? null)
                        ? trim((string) $row['method_hint'])
                        : QuestionMethodHint::inferFromQuestionText(trim((string) $row['question_text'])),
                    'difficulty' => $row['difficulty'] ?? null,
                    'source' => Question::SOURCE_AI,
                    'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
                    'created_by' => $userId,
                ]);

                if ($type === Question::TYPE_FILL_IN_BLANK) {
                    $question->blankAnswer()->create([
                        'answer_format' => $row['answer_format'],
                        'correct_answer' => trim((string) $row['correct_answer']),
                        'decimal_places' => $row['decimal_places'] ?? null,
                    ]);
                } else {
                    foreach ($row['options'] as $optionIndex => $optionText) {
                        QuestionOption::create([
                            'question_id' => $question->id,
                            'option_text' => $optionText,
                            'is_correct' => (int) $row['correct_index'] === $optionIndex,
                            'sort_order' => $optionIndex + 1,
                        ]);
                    }
                }

                $diagramFile = $this->resolveDiagramUpload($row, $index, $diagramsByName);
                if ($diagramFile instanceof UploadedFile) {
                    $this->diagrams->attach($question, $diagramFile);
                    $diagramCount++;
                }

                $saved[] = $question->fresh(['blankAnswer', 'options']);
            }

            $worksheet = $this->practiceSets->createChapterMmpSet(
                $chapter,
                collect($saved)->pluck('id')->all(),
                $userId,
                Worksheet::STATUS_PUBLISHED,
                $seedNotes,
            );

            return [
                'worksheet' => $worksheet,
                'questions' => $saved,
                'diagram_count' => $diagramCount,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, UploadedFile>  $diagramsByName
     */
    private function resolveDiagramUpload(array $row, int $index, array $diagramsByName): ?UploadedFile
    {
        $candidates = [];
        $named = strtolower(basename(trim((string) ($row['diagram_file'] ?? ''))));
        if ($named !== '') {
            $candidates[] = $named;
        }
        $n = $index + 1;
        $candidates[] = "q{$n}.png";
        $candidates[] = "q{$n}.jpg";
        $candidates[] = "q{$n}.jpeg";
        $candidates[] = "q{$n}.webp";

        foreach ($candidates as $name) {
            if (isset($diagramsByName[$name])) {
                return $diagramsByName[$name];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, int $index): array
    {
        $questionText = trim((string) ($item['question'] ?? $item['question_text'] ?? ''));
        if ($questionText === '') {
            throw new InvalidArgumentException('Question '.($index + 1).' is missing question text.');
        }

        $typeRaw = strtolower(trim((string) ($item['type'] ?? 'fill_in_blank')));
        $isMcq = in_array($typeRaw, ['mcq', 'multiple_choice'], true)
            || (isset($item['options']) && is_array($item['options']) && $item['options'] !== []);

        $topicName = trim((string) ($item['topic'] ?? $item['topic_name'] ?? ''));
        $difficulty = trim((string) ($item['difficulty'] ?? 'Hard'));
        $methodHint = trim((string) ($item['method_hint'] ?? ''));
        $explanation = trim((string) ($item['explanation'] ?? ''));

        if ($isMcq) {
            $options = array_values(array_filter(array_map(
                fn ($option) => trim((string) $option),
                is_array($item['options'] ?? null) ? $item['options'] : [],
            ), fn ($option) => $option !== ''));

            if (count($options) < 2) {
                throw new InvalidArgumentException('Question '.($index + 1).' MCQ needs at least 2 options.');
            }

            $correctIndex = isset($item['correct_index']) ? (int) $item['correct_index'] : -1;
            if ($correctIndex < 0 || $correctIndex >= count($options)) {
                throw new InvalidArgumentException('Question '.($index + 1).' has an invalid correct_index.');
            }

            return array_merge([
                'type' => Question::TYPE_MCQ,
                'topic_name' => $topicName !== '' ? $topicName : null,
                'question_text' => $questionText,
                'options' => $options,
                'correct_index' => $correctIndex,
                'method_hint' => $methodHint !== '' ? $methodHint : null,
                'explanation' => $explanation !== '' ? $explanation : null,
                'difficulty' => $difficulty !== '' ? $difficulty : 'Hard',
            ], $this->diagramMeta($item));
        }

        $format = strtolower(trim((string) ($item['answer_format'] ?? $item['format'] ?? 'integer')));
        if (! in_array($format, QuestionBlankAnswer::formats(), true)) {
            throw new InvalidArgumentException('Question '.($index + 1).' has invalid answer_format.');
        }

        $correctAnswer = trim((string) ($item['correct_answer'] ?? $item['answer'] ?? ''));
        if ($correctAnswer === '') {
            throw new InvalidArgumentException('Question '.($index + 1).' is missing correct_answer.');
        }

        if (! str_contains($questionText, '____')) {
            throw new InvalidArgumentException('Question '.($index + 1).' must include one ____ blank (or use type mcq).');
        }

        $mismatch = app(FillBlankAnswerConsistency::class)->mismatch($correctAnswer, $explanation, $format);
        if ($mismatch !== null) {
            throw new InvalidArgumentException('Question '.($index + 1).': '.$mismatch['message']);
        }

        return array_merge([
            'type' => Question::TYPE_FILL_IN_BLANK,
            'topic_name' => $topicName !== '' ? $topicName : null,
            'question_text' => $questionText,
            'answer_format' => $format,
            'correct_answer' => $correctAnswer,
            'decimal_places' => isset($item['decimal_places']) ? (int) $item['decimal_places'] : null,
            'method_hint' => $methodHint !== '' ? $methodHint : null,
            'explanation' => $explanation !== '' ? $explanation : null,
            'difficulty' => $difficulty !== '' ? $difficulty : 'Hard',
        ], $this->diagramMeta($item));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{needs_diagram: bool, diagram_file: ?string, figure_spec: ?string}
     */
    private function diagramMeta(array $item): array
    {
        $diagramFile = trim((string) ($item['diagram_file'] ?? ''));
        $figureSpec = trim((string) ($item['figure_spec'] ?? $item['figure_notes'] ?? ''));

        return [
            'needs_diagram' => DiagramQuestionSupport::needsDiagram($item),
            'diagram_file' => $diagramFile !== '' ? $diagramFile : null,
            'figure_spec' => $figureSpec !== '' ? $figureSpec : null,
        ];
    }
}
