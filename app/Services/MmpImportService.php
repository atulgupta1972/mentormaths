<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\SyllabusChapter;
use App\Models\Worksheet;
use App\Support\FillBlankAnswerConsistency;
use App\Support\QuestionBankPurpose;
use App\Support\QuestionMethodHint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MmpImportService
{
    public function __construct(
        private FillBlankImportService $fillBlankImport,
        private PracticeSetService $practiceSets,
    ) {}

    /**
     * @param  array{total?: int, seed?: string, figure_notes?: string}  $options
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
        $total = max(5, min(12, (int) ($options['total'] ?? 8)));
        $seed = trim((string) ($options['seed'] ?? ''));
        $figureNotes = trim((string) ($options['figure_notes'] ?? ''));

        if ($seed === '') {
            throw new InvalidArgumentException('Paste a seed situation before generating the MMP prompt.');
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

        return <<<PROMPT
Create Mentormaths Perfection (MMP) maths questions for an exhaustive practice set. Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Seed situation (this must become question 1 — rewritten as a complete self-contained sum):
{$seed}
{$figureBlock}
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
     * @return array{worksheet: Worksheet, questions: list<Question>}
     */
    public function saveSet(
        SyllabusChapter $chapter,
        array $rows,
        int $userId,
        ?string $seedNotes = null,
    ): array {
        if ($rows === []) {
            throw new InvalidArgumentException('Nothing to save — preview the JSON first.');
        }

        return DB::transaction(function () use ($chapter, $rows, $userId, $seedNotes) {
            $saved = [];

            foreach ($rows as $row) {
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
            ];
        });
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

            return [
                'type' => Question::TYPE_MCQ,
                'topic_name' => $topicName !== '' ? $topicName : null,
                'question_text' => $questionText,
                'options' => $options,
                'correct_index' => $correctIndex,
                'method_hint' => $methodHint !== '' ? $methodHint : null,
                'explanation' => $explanation !== '' ? $explanation : null,
                'difficulty' => $difficulty !== '' ? $difficulty : 'Hard',
            ];
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

        return [
            'type' => Question::TYPE_FILL_IN_BLANK,
            'topic_name' => $topicName !== '' ? $topicName : null,
            'question_text' => $questionText,
            'answer_format' => $format,
            'correct_answer' => $correctAnswer,
            'decimal_places' => isset($item['decimal_places']) ? (int) $item['decimal_places'] : null,
            'method_hint' => $methodHint !== '' ? $methodHint : null,
            'explanation' => $explanation !== '' ? $explanation : null,
            'difficulty' => $difficulty !== '' ? $difficulty : 'Hard',
        ];
    }
}
