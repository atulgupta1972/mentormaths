<?php

namespace App\Services;

use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;

/**
 * Turn a chapter worksheet PDF into a Cursor prompt.
 * Every source question becomes a fill-in-the-blank unless one checkable blank cannot carry it.
 */
class PdfFillBlankExtractService
{
    /**
     * @return list<array{section: string, number: int, text: string, label: string}>
     */
    public function splitSourceQuestions(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split('/\n/u', $text) ?: [];

        $section = '';
        $sectionTitle = '';
        $current = null;
        $questions = [];

        $flush = function () use (&$current, &$questions): void {
            if ($current === null) {
                return;
            }

            $body = trim(preg_replace('/[ \t]+/u', ' ', $current['text']) ?? $current['text']);
            $body = trim(preg_replace('/\s*\n\s*/u', ' ', $body) ?? $body);

            if ($body !== '') {
                $label = $current['section'] !== ''
                    ? $current['section'].'.'.$current['number']
                    : (string) $current['number'];
                $questions[] = [
                    'section' => $current['section_title'],
                    'number' => $current['number'],
                    'text' => $body,
                    'label' => $label,
                ];
            }

            $current = null;
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^([A-Z])\.\s+(\S.*)$/u', $line, $sectionMatch)
                && ! preg_match('/^\d/u', $sectionMatch[2])) {
                $flush();
                $section = $sectionMatch[1];
                $sectionTitle = trim($sectionMatch[2]);

                continue;
            }

            if (preg_match('/^(\d+)[.)]\s+(\S.*)$/u', $line, $questionMatch)) {
                $flush();
                $current = [
                    'section' => $section,
                    'section_title' => $sectionTitle,
                    'number' => (int) $questionMatch[1],
                    'text' => $questionMatch[2],
                ];

                continue;
            }

            if ($current !== null) {
                $current['text'] .= ' '.$line;
            }
        }

        $flush();

        return $questions;
    }

    public function cursorPrompt(SyllabusChapter $chapter, string $extractedText, ?SyllabusTopic $onlyTopic = null): string
    {
        $chapter->loadMissing([
            'topics' => fn ($q) => $q->orderBy('sort_order'),
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
        ]);

        $questions = $this->splitSourceQuestions($extractedText);
        $source = $this->sourceBlock($questions, $extractedText);
        $countLine = $questions !== []
            ? 'There are '.count($questions).' source questions below. Return exactly '.count($questions).' JSON items, in the same order. Do not merge, skip, or invent questions.'
            : 'Extract every numbered question in the source. Do not merge, skip, or invent questions.';

        $topicRule = $onlyTopic
            ? 'Set "topic" on every item to exactly: '.$onlyTopic->name
            : "Set \"topic\" on every item to the exact syllabus topic name below that best matches the source section. Do not invent topic names.\n".$chapter->topics->pluck('name')->map(fn ($name) => '- '.$name)->implode("\n");

        $context = $this->context($chapter, $onlyTopic);

        return <<<PROMPT
Convert every question in the source worksheet into MentorMaths practice questions. Return ONLY valid JSON (no markdown fences).

Context:
{$context}

{$countLine}

How to convert each question:
- Default is fill-in-the-blank. Rewrite the source so the student fills ONE blank shown as "____".
- Keep the original numbers and the skill the question is testing. Do not replace the question with a different sum.
- Prefer answer_format "integer", "decimal", or "fraction".
- Use "text" only for a short answer of at most 60 characters (an ordered pair, a short equation, or values in a stated order such as -3, 5, -12). Use an ASCII hyphen for negatives.
- "correct_answer" must match the blank exactly and must be at most 60 characters.
- The explanation must end with the same value as correct_answer. For numbers write "Final answer = -4". For text write "Final answer = -3, 5, -12".
- "method_hint" is theory or a rule only. Do not put the final answer in the hint.
- If the source asks for several numbers, keep one item: ask for them in a fixed order as one short text answer when it fits, otherwise ask for the single value that checks the skill and record the other values in the explanation.
- If the source says "draw" or "plot" but also asks for a number (intercept, slope, solution, coordinate), drop the drawing instruction and blank the number.
- Use type "mcq" ONLY when one checkable blank cannot carry the question: a graph with no numeric target, "what do you notice", a reason for each of several statements, or a classification that is not one short answer.
- An MCQ has exactly 4 options, exactly one correct, and "correct_index" 0, 1, 2, or 3. Do not put ____ in an MCQ.
- Set "difficulty" to Easy, Medium, or Hard.
- {$topicRule}

JSON format:
{
  "questions": [
    {
      "type": "fill_in_blank",
      "source_label": "A.1",
      "topic": "Exact topic name",
      "question": "The slope of the line through A(1, 2) and B(5, 10) is ____.",
      "answer_format": "integer",
      "correct_answer": "2",
      "method_hint": "Slope is the change in y divided by the change in x.",
      "explanation": "(10 - 2) / (5 - 1) = 8 / 4 = 2. Final answer = 2",
      "difficulty": "Easy"
    },
    {
      "type": "mcq",
      "source_label": "A.4",
      "topic": "Exact topic name",
      "question": "Which equation is linear in two variables?",
      "options": ["x^2 + y = 4", "2x + 3y = 8", "xy = 12", "7x - 5 = 0"],
      "correct_index": 1,
      "method_hint": "A linear equation in two variables has degree 1 and contains both variables.",
      "explanation": "2x + 3y = 8 is degree 1 in both x and y. Final answer = 2x + 3y = 8",
      "difficulty": "Medium"
    }
  ]
}

Source:
{$source}
PROMPT;
    }

    /**
     * @param  list<array{section: string, number: int, text: string, label: string}>  $questions
     */
    private function sourceBlock(array $questions, string $extractedText): string
    {
        if ($questions === []) {
            $text = trim($extractedText);
            if (mb_strlen($text) > 18000) {
                $text = mb_substr($text, 0, 18000)."\n\n[... PDF text truncated ...]\n";
            }

            return "---\n{$text}\n---";
        }

        $blocks = [];
        $used = 0;

        foreach ($questions as $question) {
            $section = $question['section'] !== '' ? $question['section'] : 'Worksheet';
            $block = $question['label'].' ['.$section.'] '.$question['text'];
            if ($used + mb_strlen($block) > 18000) {
                $blocks[] = '[... remaining source questions truncated ...]';
                break;
            }

            $blocks[] = $block;
            $used += mb_strlen($block);
        }

        return implode("\n\n", $blocks);
    }

    private function context(SyllabusChapter $chapter, ?SyllabusTopic $onlyTopic): string
    {
        $version = $chapter->syllabusVersion;

        return collect([
            $version ? 'Board: '.$version->board->code : null,
            $version ? 'Class: '.$version->gradeLevel->name : null,
            $version ? 'Typical student age: '.$version->gradeLevel->typicalAge().' years' : null,
            $version ? 'Academic year: '.$version->academicYear->name : null,
            'Chapter: '.$chapter->chapter_number.' — '.$chapter->name,
            $onlyTopic ? 'Topic: '.$onlyTopic->name : null,
            $onlyTopic && $onlyTopic->learning_outcomes ? 'Key concepts: '.$onlyTopic->learning_outcomes : null,
        ])->filter()->implode("\n");
    }
}
