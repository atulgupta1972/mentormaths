<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Support\DiagramQuestionSupport;
use App\Support\QuestionBankPurpose;
use App\Support\QuestionMethodHint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FillBlankImportService
{
    /**
     * @param  array{total?: int, easy?: int, medium?: int, hard?: int, focus?: string}  $options
     */
    public function cursorPrompt(SyllabusTopic $topic, array $options = []): string
    {
        $topic->loadMissing(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear']);

        $total = max(1, min(50, (int) ($options['total'] ?? 6)));
        $easy = max(0, (int) ($options['easy'] ?? 2));
        $medium = max(0, (int) ($options['medium'] ?? 2));
        $hard = max(0, (int) ($options['hard'] ?? 2));
        $focus = trim((string) ($options['focus'] ?? ''));

        if ($easy + $medium + $hard !== $total) {
            $easy = intdiv($total, 3);
            $medium = intdiv($total, 3);
            $hard = $total - $easy - $medium;
        }

        $chapter = $topic->chapter;
        $version = $chapter?->syllabusVersion;

        $context = collect([
            $version ? "Board: {$version->board->code}" : null,
            $version ? "Class: {$version->gradeLevel->name}" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            $chapter ? "Chapter: {$chapter->chapter_number} — {$chapter->name}" : null,
            "Topic: {$topic->name}",
            $topic->learning_outcomes ? "Key concepts: {$topic->learning_outcomes}" : null,
        ])->filter()->implode("\n");

        $focusLine = $focus !== '' ? "\n- Focus: {$focus}" : '';

        return <<<PROMPT
Create fill-in-the-blank maths questions for guided practice. Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Requirements:
- Exactly {$total} questions: Easy {$easy}, Medium {$medium}, Hard {$hard}
- Each question is a short sum with ONE blank shown as "____" in the question text
- Use only these answer formats: "integer", "decimal", or "fraction"
- "correct_answer" must match the blank exactly (examples: "42", "-3.5", "3/4", "1 1/2")
- For decimals, include "decimal_places" when needed (e.g. 2 for money-style answers)
- Include "method_hint": theory/rules ONLY — no final numeric answer
- Include "explanation": full teacher-only working with the final answer
- Class-appropriate CBSE/ICSE level{$focusLine}
- Do NOT include MCQ options

JSON format:
{
  "questions": [
    {
      "question": "(-12) + 8 = ____",
      "answer_format": "integer",
      "correct_answer": "-4",
      "method_hint": "Add integers with different signs by subtracting and keeping the sign of the larger absolute value.",
      "explanation": "|-12| > |8|, difference is 4, result is negative: -4",
      "difficulty": "Easy"
    }
  ]
}
PROMPT;
    }

    /**
     * @param  list<array{topic_id: int, topic_name?: string, easy?: int, medium?: int, hard?: int}>  $planRows
     */
    public function cursorPromptForWrittenChapter(SyllabusChapter $chapter, array $planRows): string
    {
        $prompt = $this->cursorPromptForChapter($chapter, $planRows);

        if (! DiagramQuestionSupport::looksLikeGeometryChapter($chapter)) {
            return $prompt;
        }

        return $prompt.$this->diagramPromptBlock();
    }

    private function diagramPromptBlock(): string
    {
        return <<<'PROMPT'

Diagram sums (geometry chapters only):
- For every sum that needs a figure, set "needs_diagram": true and "diagram_file": "qN.jpg" (N = question order: q1.jpg, q2.jpg, …)
- Start the question with "In the figure, …" when needs_diagram is true
- For algebra/number-only sums, omit needs_diagram or set it to false (diagram fields are ignored without images in the zip)
PROMPT;
    }

    /**
     * @param  list<array{topic_id: int, topic_name?: string, easy?: int, medium?: int, hard?: int}>  $planRows
     */
    public function cursorPromptForChapter(SyllabusChapter $chapter, array $planRows): string
    {
        $chapter->loadMissing([
            'topics' => fn ($q) => $q->orderBy('sort_order'),
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
        ]);

        $lines = [];
        $total = 0;

        foreach ($planRows as $row) {
            $easy = max(0, (int) ($row['easy'] ?? 0));
            $medium = max(0, (int) ($row['medium'] ?? 0));
            $hard = max(0, (int) ($row['hard'] ?? 0));
            $subtotal = $easy + $medium + $hard;

            if ($subtotal === 0) {
                continue;
            }

            $name = trim((string) ($row['topic_name'] ?? ''));
            if ($name === '' && ! empty($row['topic_id'])) {
                $name = (string) ($chapter->topics->firstWhere('id', (int) $row['topic_id'])?->name ?? '');
            }

            if ($name === '') {
                continue;
            }

            $lines[] = "- {$name}: Easy {$easy}, Medium {$medium}, Hard {$hard}";
            $total += $subtotal;
        }

        if ($total === 0) {
            throw new InvalidArgumentException('Enter at least one question in the chapter plan.');
        }

        $planBlock = implode("\n", $lines);
        $context = $this->chapterContext($chapter);

        return <<<PROMPT
Create fill-in-the-blank maths questions for an entire chapter. Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Requirements:
- Exactly {$total} questions total, distributed as follows:
{$planBlock}
- Each question MUST include "topic" with the exact topic name from the plan above
- Each question is a short sum with ONE blank shown as "____" in the question text
- Use only these answer formats: "integer", "decimal", or "fraction"
- "correct_answer" must match the blank exactly (examples: "42", "-3.5", "3/4", "1 1/2")
- For decimals, include "decimal_places" when needed
- Include "method_hint": theory/rules ONLY — no final numeric answer
- Include "explanation": full teacher-only working with the final answer
- Set "difficulty" on each question to Easy, Medium, or Hard matching the plan row
- Do NOT include MCQ options

JSON format:
{
  "questions": [
    {
      "topic": "Exact topic name from plan",
      "question": "In the figure, … = ____",
      "needs_diagram": true,
      "diagram_file": "q1.jpg",
      "answer_format": "integer",
      "correct_answer": "-4",
      "method_hint": "Add integers with different signs by subtracting and keeping the sign of the larger absolute value.",
      "explanation": "|-12| > |8|, difference is 4, result is negative: -4",
      "difficulty": "Easy"
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
        $data = json_decode($this->stripMarkdownFences($json), true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON. Paste a {"questions": [...]} object from Cursor.');
        }

        $items = isset($data['questions']) && is_array($data['questions'])
            ? $data['questions']
            : $data;

        if ($items === []) {
            throw new InvalidArgumentException('No questions found in JSON.');
        }

        $parsed = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $parsed[] = $this->normalizeItem($item, $index);
        }

        if ($parsed === []) {
            throw new InvalidArgumentException('Could not parse any fill-in-the-blank questions.');
        }

        return $parsed;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Question>
     */
    public function saveRows(
        SyllabusTopic $topic,
        array $rows,
        int $userId,
        string $source = Question::SOURCE_AI,
        string $bankPurpose = QuestionBankPurpose::PRACTICE_SET,
    ): array {
        return DB::transaction(function () use ($topic, $rows, $userId, $source, $bankPurpose) {
            $saved = [];

            foreach ($rows as $row) {
                if (trim((string) ($row['question_text'] ?? '')) === '') {
                    continue;
                }

                $question = Question::create([
                    'syllabus_topic_id' => $topic->id,
                    'type' => Question::TYPE_FILL_IN_BLANK,
                    'question_text' => trim((string) $row['question_text']),
                    'explanation' => QuestionMethodHint::sanitizeExplanation($row['explanation'] ?? null),
                    'method_hint' => filled($row['method_hint'] ?? null)
                        ? trim((string) $row['method_hint'])
                        : QuestionMethodHint::inferFromQuestionText(trim((string) $row['question_text'])),
                    'difficulty' => $row['difficulty'] ?? null,
                    'source' => $source,
                    'bank_purpose' => QuestionBankPurpose::normalize($bankPurpose),
                    'created_by' => $userId,
                ]);

                $question->blankAnswer()->create([
                    'answer_format' => $row['answer_format'],
                    'correct_answer' => trim((string) $row['correct_answer']),
                    'decimal_places' => $row['decimal_places'] ?? null,
                ]);

                $saved[] = $question->load('blankAnswer');
            }

            return $saved;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Question>
     */
    public function saveChapterRows(
        SyllabusChapter $chapter,
        array $rows,
        int $userId,
        string $source = Question::SOURCE_AI,
        string $bankPurpose = QuestionBankPurpose::PRACTICE_SET,
    ): array {
        $chapter->loadMissing(['topics']);

        return DB::transaction(function () use ($chapter, $rows, $userId, $source, $bankPurpose) {
            $saved = [];

            foreach ($rows as $row) {
                if (trim((string) ($row['question_text'] ?? '')) === '') {
                    continue;
                }

                $topicId = $this->resolveTopicIdForChapterRow($chapter, $row);
                $topic = $chapter->topics->firstWhere('id', $topicId)
                    ?? SyllabusTopic::query()->findOrFail($topicId);

                $question = Question::create([
                    'syllabus_topic_id' => $topic->id,
                    'type' => Question::TYPE_FILL_IN_BLANK,
                    'question_text' => trim((string) $row['question_text']),
                    'explanation' => QuestionMethodHint::sanitizeExplanation($row['explanation'] ?? null),
                    'method_hint' => filled($row['method_hint'] ?? null)
                        ? trim((string) $row['method_hint'])
                        : QuestionMethodHint::inferFromQuestionText(trim((string) $row['question_text'])),
                    'difficulty' => $row['difficulty'] ?? null,
                    'source' => $source,
                    'bank_purpose' => QuestionBankPurpose::normalize($bankPurpose),
                    'created_by' => $userId,
                ]);

                $question->blankAnswer()->create([
                    'answer_format' => $row['answer_format'],
                    'correct_answer' => trim((string) $row['correct_answer']),
                    'decimal_places' => $row['decimal_places'] ?? null,
                ]);

                $saved[] = $question->load('blankAnswer');
            }

            return $saved;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function resolveTopicIdForChapterRow(SyllabusChapter $chapter, array $row): int
    {
        if (! empty($row['syllabus_topic_id'])) {
            $topicId = (int) $row['syllabus_topic_id'];
            if ($chapter->topics()->whereKey($topicId)->exists()) {
                return $topicId;
            }
        }

        $name = trim((string) ($row['topic'] ?? $row['topic_name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Each question must include a topic name for chapter import.');
        }

        $topic = $chapter->topics()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if (! $topic) {
            throw new InvalidArgumentException("Unknown topic \"{$name}\" for this chapter.");
        }

        return $topic->id;
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

        $format = strtolower(trim((string) ($item['answer_format'] ?? $item['format'] ?? 'integer')));

        if (! in_array($format, QuestionBlankAnswer::formats(), true)) {
            throw new InvalidArgumentException('Question '.($index + 1).' has invalid answer_format (use integer, decimal, fraction, or text).');
        }

        $correctAnswer = trim((string) ($item['correct_answer'] ?? $item['answer'] ?? ''));

        if ($correctAnswer === '') {
            throw new InvalidArgumentException('Question '.($index + 1).' is missing correct_answer.');
        }

        $format = $this->resolveAnswerFormat($format, $correctAnswer);

        return [
            'question_text' => $questionText,
            'topic_name' => trim((string) ($item['topic'] ?? $item['topic_name'] ?? '')),
            'syllabus_topic_id' => $item['syllabus_topic_id'] ?? $item['topic_id'] ?? null,
            'answer_format' => $format,
            'correct_answer' => $correctAnswer,
            'decimal_places' => isset($item['decimal_places']) ? (int) $item['decimal_places'] : null,
            'explanation' => trim((string) ($item['explanation'] ?? '')),
            'method_hint' => trim((string) ($item['method_hint'] ?? $item['hint'] ?? '')),
            'difficulty' => trim((string) ($item['difficulty'] ?? '')),
            'needs_diagram' => DiagramQuestionSupport::needsDiagram($item),
        ];
    }

    private function resolveAnswerFormat(string $format, string $correctAnswer): string
    {
        if ($format === QuestionBlankAnswer::FORMAT_TEXT) {
            return QuestionBlankAnswer::FORMAT_TEXT;
        }

        if (preg_match('/^(<=|>=|!=|[<=>≤≥≠])$/u', $correctAnswer)) {
            return QuestionBlankAnswer::FORMAT_TEXT;
        }

        if ($format === QuestionBlankAnswer::FORMAT_FRACTION
            && ! preg_match('/^-?\d+(?:\.\d+)?(?:\s+\d+\s*\/\s*\d+|\s*\/\s*\d+)?$/', $correctAnswer)) {
            return QuestionBlankAnswer::FORMAT_TEXT;
        }

        return $format;
    }

    private function chapterContext(SyllabusChapter $chapter): string
    {
        $chapter->loadMissing([
            'topics' => fn ($q) => $q->orderBy('sort_order'),
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
        ]);

        $version = $chapter->syllabusVersion;
        $topicList = $chapter->topics->pluck('name')->implode(', ');

        return collect([
            $version ? "Board: {$version->board->code}" : null,
            $version ? "Class: {$version->gradeLevel->name}" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            "Chapter: {$chapter->chapter_number} — {$chapter->name}",
            $topicList !== '' ? "Topics: {$topicList}" : null,
        ])->filter()->implode("\n");
    }

    private function stripMarkdownFences(string $json): string
    {
        $json = trim($json);

        if (preg_match('/^```(?:json)?\s*(.*?)```\s*$/is', $json, $matches)) {
            return trim($matches[1]);
        }

        return preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/', '', $json) ?? $json) ?? $json;
    }
}
