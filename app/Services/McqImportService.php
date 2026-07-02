<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SyllabusTopic;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class McqImportService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function parseJson(string $json): array
    {
        $data = $this->decodeJsonPayload($json);

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
            throw new InvalidArgumentException('Could not parse any questions from JSON.');
        }

        return $parsed;
    }

    /**
     * Parse numbered MCQs (a./b./c./d.) from worksheet PDF text when an answer key is present.
     *
     * @return list<array<string, mixed>>
     */
    public function parseFromWorksheetText(string $text): array
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        $answers = $this->parseWorksheetAnswerKey($normalized);
        $blocks = $this->extractWorksheetMcqBlocks($normalized);

        if ($blocks === []) {
            throw new InvalidArgumentException('Could not find numbered MCQs with a/b/c/d options in the PDF text.');
        }

        $parsed = [];

        foreach ($blocks as $number => $block) {
            $correctIndex = null;
            if (isset($answers[$number])) {
                $correctIndex = ord(strtolower($answers[$number])) - ord('a');
            }

            $item = [
                'question' => $block['question'],
                'options' => $block['options'],
                'correct_index' => $correctIndex,
                'explanation' => $correctIndex !== null
                    ? 'Answer key: '.$answers[$number].'.'
                    : null,
                'difficulty' => 'Easy',
            ];

            $parsed[] = $this->normalizeItem($item, $number - 1);
        }

        if ($parsed === []) {
            throw new InvalidArgumentException('Could not parse any MCQs from PDF text.');
        }

        return $parsed;
    }

    /**
     * @return array<int, string>
     */
    private function parseWorksheetAnswerKey(string $text): array
    {
        $answers = [];

        if (! preg_match('/answer\s*key\s*:?\s*(.+)$/iu', $text, $keyMatch)) {
            return $answers;
        }

        preg_match_all('/(\d+)\.\s*([a-d])/iu', $keyMatch[1], $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $answers[(int) $match[1]] = strtolower($match[2]);
        }

        return $answers;
    }

    /**
     * @return array<int, array{question: string, options: list<string>}>
     */
    private function extractWorksheetMcqBlocks(string $text): array
    {
        $stopPattern = '/(?:Fill in the blanks|Answer key|Material downloaded)/iu';
        $mcqSection = preg_split($stopPattern, $text, 2)[0] ?? $text;

        preg_match_all(
            '/(\d+)\.\s*(.+?)\s+a\.\s*(.+?)\s+b\.\s*(.+?)\s+c\.\s*(.+?)\s+d\.\s*(.+?)(?=\s+\d+\.\s|\s+Fill\s+in|\s+Answer\s+key|$)/iu',
            $mcqSection,
            $matches,
            PREG_SET_ORDER,
        );

        $blocks = [];

        foreach ($matches as $match) {
            $options = [
                trim($match[3]),
                trim($match[4]),
                trim($match[5]),
                trim($match[6]),
            ];

            if (collect($options)->filter()->isEmpty()) {
                continue;
            }

            $blocks[(int) $match[1]] = [
                'question' => trim($match[2]),
                'options' => $options,
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $json): array
    {
        $json = $this->stripMarkdownFences($json);
        $data = json_decode($json, true);

        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{\s*"questions"\s*:\s*\[[\s\S]*\]\s*\}/', $json, $matches)) {
            $data = json_decode($matches[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        $trimmed = rtrim($json);
        while (strlen($trimmed) > 2) {
            $data = json_decode($trimmed, true);
            if (is_array($data)) {
                return $data;
            }

            if (str_ends_with($trimmed, '}')) {
                $trimmed = rtrim(substr($trimmed, 0, -1));

                continue;
            }

            break;
        }

        throw new InvalidArgumentException('Invalid JSON. Paste a JSON array or {"questions": [...]} from Cursor.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<Question>
     */
    public function saveRows(SyllabusTopic $topic, array $rows, int $userId, string $source = Question::SOURCE_AI): array
    {
        return DB::transaction(function () use ($topic, $rows, $userId, $source) {
            $saved = [];

            foreach ($rows as $row) {
                if (trim((string) ($row['question_text'] ?? '')) === '') {
                    continue;
                }

                $question = Question::create([
                    'syllabus_topic_id' => $topic->id,
                    'type' => Question::TYPE_MCQ,
                    'question_text' => trim((string) $row['question_text']),
                    'explanation' => $row['explanation'] ?? null,
                    'difficulty' => $row['difficulty'] ?? null,
                    'source' => $source,
                    'created_by' => $userId,
                ]);

                $this->syncOptions($question, $row['options'] ?? []);

                $saved[] = $question->load('options');
            }

            return $saved;
        });
    }

    public function syncQuestion(Question $question, array $data): Question
    {
        return DB::transaction(function () use ($question, $data) {
            $question->update([
                'question_text' => trim((string) ($data['question_text'] ?? $question->question_text)),
                'explanation' => $data['explanation'] ?? null,
                'difficulty' => $data['difficulty'] ?? null,
            ]);

            $question->options()->delete();
            $this->syncOptions($question, $data['options'] ?? []);

            return $question->fresh('options');
        });
    }

    /**
     * @param  array{total?: int, easy?: int, medium?: int, hard?: int, focus?: string}  $options
     */
    public function cursorPrompt(SyllabusTopic $topic, array $options = []): string
    {
        $context = $this->topicContext($topic);

        $total = max(1, min(50, (int) ($options['total'] ?? 6)));
        $easy = max(0, (int) ($options['easy'] ?? 2));
        $medium = max(0, (int) ($options['medium'] ?? 2));
        $hard = max(0, (int) ($options['hard'] ?? 2));
        $focus = trim((string) ($options['focus'] ?? ''));

        if ($easy + $medium + $hard === 0) {
            $easy = (int) ceil($total / 3);
            $medium = (int) floor($total / 3);
            $hard = $total - $easy - $medium;
        } elseif ($easy + $medium + $hard !== $total) {
            $scale = $total / max(1, $easy + $medium + $hard);
            $easy = max(0, (int) round($easy * $scale));
            $medium = max(0, (int) round($medium * $scale));
            $hard = max(0, $total - $easy - $medium);
        }

        $difficultyBlock = "- Exactly {$total} questions total\n- Easy: {$easy}, Medium: {$medium}, Hard: {$hard}";
        $focusBlock = $focus !== ''
            ? "\nFocus / sum types (priority):\n{$focus}"
            : '';

        return $this->basePrompt(
            'Create MCQ questions for this maths topic. Return ONLY valid JSON (no markdown fences).',
            $context,
            <<<REQ
Requirements:
{$difficultyBlock}
- Class-appropriate CBSE/ICSE level
- 4 options each, exactly one correct answer
- Include a short explanation per question
- Set "difficulty" on each question to Easy, Medium, or Hard{$focusBlock}
REQ,
        );
    }

    public function cursorPromptFromSumsPdf(SyllabusTopic $topic, string $extractedText): string
    {
        $context = $this->topicContext($topic);
        $text = $this->truncatePdfText($extractedText);

        return $this->basePrompt(
            'Convert the maths sums below into MCQ questions. Return ONLY valid JSON (no markdown fences).',
            $context,
            <<<REQ
Requirements:
- Turn each sum into one MCQ with 4 options (one correct, three plausible wrong answers)
- Keep class-appropriate CBSE/ICSE level
- Include a short explanation per question
- Set "difficulty" on each question to Easy, Medium, or Hard
- If a sum is unclear, skip it rather than inventing numbers

Source sums extracted from PDF:
---
{$text}
---
REQ,
        );
    }

    public function cursorPromptFromMcqPdf(SyllabusTopic $topic, string $extractedText): string
    {
        $context = $this->topicContext($topic);
        $text = $this->truncatePdfText($extractedText);

        return $this->basePrompt(
            'Parse the MCQ questions below from a PDF into structured JSON. Return ONLY valid JSON (no markdown fences).',
            $context,
            <<<REQ
Requirements:
- Extract every MCQ you can find (question, 4 options, correct answer, explanation if present)
- Preserve the original question wording where possible
- If correct answer is marked (e.g. bold, *, tick, or "Ans: B"), use that
- If correct answer is not marked, infer the best option and note in explanation
- Set "difficulty" to Easy, Medium, or Hard where possible
- Skip incomplete items rather than guessing

Source MCQs extracted from PDF:
---
{$text}
---
REQ,
        );
    }

    private function topicContext(SyllabusTopic $topic): string
    {
        $topic->loadMissing(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear']);

        $chapter = $topic->chapter;
        $version = $chapter?->syllabusVersion;

        return collect([
            $version ? "Board: {$version->board->code}" : null,
            $version ? "Class: {$version->gradeLevel->name}" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            $chapter ? "Chapter: {$chapter->chapter_number} — {$chapter->name}" : null,
            "Topic: {$topic->name}",
            $topic->learning_outcomes ? "Key concepts: {$topic->learning_outcomes}" : null,
            $topic->difficulty ? "Syllabus difficulty: {$topic->difficulty}" : null,
        ])->filter()->implode("\n");
    }

    private function basePrompt(string $intro, string $context, string $requirements): string
    {
        return <<<PROMPT
{$intro}

Context:
{$context}

{$requirements}

JSON format:
{
  "questions": [
    {
      "question": "Question text here",
      "options": ["Option A", "Option B", "Option C", "Option D"],
      "correct_index": 0,
      "explanation": "Why this answer is correct",
      "difficulty": "Easy"
    }
  ]
}
PROMPT;
    }

    private function truncatePdfText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) <= 12000) {
            return $text;
        }

        return mb_substr($text, 0, 12000)."\n\n[... PDF text truncated — first 12,000 characters only ...]";
    }

    private function stripMarkdownFences(string $json): string
    {
        $json = trim($json);

        if (preg_match('/^```(?:json)?\s*(.*?)```\s*$/is', $json, $matches)) {
            return trim($matches[1]);
        }

        return preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/', '', $json) ?? $json) ?? $json;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, int $index): array
    {
        $questionText = trim((string) ($item['question'] ?? $item['question_text'] ?? ''));
        $options = $item['options'] ?? [];

        if (! is_array($options)) {
            $options = [];
        }

        $normalizedOptions = [];
        $correctIndex = isset($item['correct_index']) ? (int) $item['correct_index'] : null;

        if ($correctIndex === null && isset($item['correct_answer'])) {
            $correctLetter = strtoupper(trim((string) $item['correct_answer']));
            $correctIndex = ord($correctLetter) - ord('A');
        }

        foreach (array_values($options) as $optIndex => $option) {
            if (is_array($option)) {
                $text = trim((string) ($option['text'] ?? $option['option'] ?? ''));
                $isCorrect = (bool) ($option['is_correct'] ?? false);
            } else {
                $text = trim((string) $option);
                $isCorrect = $correctIndex === $optIndex;
            }

            if ($text === '') {
                continue;
            }

            $normalizedOptions[] = [
                'option_text' => $text,
                'is_correct' => $isCorrect || $correctIndex === $optIndex,
                'sort_order' => count($normalizedOptions) + 1,
            ];
        }

        if ($correctIndex !== null && isset($normalizedOptions[$correctIndex])) {
            foreach ($normalizedOptions as $i => &$opt) {
                $opt['is_correct'] = $i === $correctIndex;
            }
            unset($opt);
        }

        if ($normalizedOptions !== [] && ! collect($normalizedOptions)->contains('is_correct', true)) {
            $normalizedOptions[0]['is_correct'] = true;
        }

        return [
            'question_text' => $questionText,
            'explanation' => trim((string) ($item['explanation'] ?? '')) ?: null,
            'difficulty' => trim((string) ($item['difficulty'] ?? '')) ?: null,
            'options' => $normalizedOptions,
            '_row' => $index + 1,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        foreach (array_values($options) as $index => $option) {
            $text = trim((string) ($option['option_text'] ?? $option['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            QuestionOption::create([
                'question_id' => $question->id,
                'option_text' => $text,
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => (int) ($option['sort_order'] ?? $index + 1),
            ]);
        }
    }
}
