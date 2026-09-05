<?php

namespace App\Services;

use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ConceptPathStatus;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConceptPathService
{
    public function cursorPrompt(TextbookChapter $chapter): string
    {
        $chapter->loadMissing([
            'textbook.gradeLevel',
            'textbook.board',
            'syllabusChapter.topics' => fn ($q) => $q->orderBy('sort_order'),
            'syllabusChapter.syllabusVersion.board',
            'syllabusChapter.syllabusVersion.gradeLevel',
            'syllabusChapter.syllabusVersion.academicYear',
        ]);

        if (! $chapter->pdf_path) {
            throw new InvalidArgumentException('Upload the chapter PDF before generating the concept-path prompt.');
        }

        $syllabus = $chapter->syllabusChapter;
        $version = $syllabus?->syllabusVersion;
        $topics = $syllabus?->topics ?? collect();

        $topicLines = $topics->isEmpty()
            ? '- (No syllabus topics linked — infer topic labels from the PDF headings.)'
            : $topics->map(fn ($t) => '- '.$t->name.(filled($t->learning_outcomes) ? ' — '.$t->learning_outcomes : ''))->implode("\n");

        $context = collect([
            $version?->board?->code ? 'Board: '.$version->board->code : ($chapter->textbook?->board?->code ? 'Board: '.$chapter->textbook->board->code : null),
            $version?->gradeLevel?->name ? 'Class: '.$version->gradeLevel->name : ($chapter->textbook?->gradeLevel?->name ? 'Class: '.$chapter->textbook->gradeLevel->name : null),
            $version?->gradeLevel ? 'Typical student age: '.$version->gradeLevel->typicalAge().' years' : null,
            $version?->academicYear?->name ? 'Academic year: '.$version->academicYear->name : null,
            'Textbook: '.($chapter->textbook?->name ?? 'Book').' ('.($chapter->textbook?->code ?? '').')',
            'Chapter: '.$chapter->displayChapterNumber().' — '.$chapter->title,
            $syllabus ? 'Syllabus chapter: '.$syllabus->name : null,
            "Syllabus topics / key concepts:\n{$topicLines}",
        ])->filter()->implode("\n");

        $pdfHint = 'Open / attach the chapter PDF for this textbook chapter (download from Mentormaths chapter page). Extract ONLY from that PDF — do not invent off-syllabus topics.';

        return <<<PROMPT
You are designing a CONCEPT PATH for Indian school maths (CBSE/ICSE).
Return ONLY valid JSON (no markdown fences).

{$pdfHint}

Context:
{$context}

Goal:
Build a step-by-step teaching deck that flashes chapter concepts ONE idea at a time,
interleaved with tiny checks, so a student can learn the chapter foundations before MCQ / drill.

Card types:
1) "teach" — short concept flash (title + body + one worked example). Optional common_mistake.
2) "check" — 1 to 3 very easy questions to confirm that concept (MCQ with 4 options OR fill_blank).

Pedagogy rules:
- Cover the WHOLE chapter concept flow in teaching order (definitions → notation → building blocks → common traps → simple use).
- One idea per teach card. Keep body to 2–5 short sentences. Use class-appropriate language.
- After every 1–2 teach cards, add a check card.
- Explicitly include “common mistake” teach cards where students confuse notation (e.g. 3² vs 3×2, like terms, signs).
- Check questions must be EASY — prove understanding, not assess the chapter.
- Prefer fill_blank for simple numeric answers; MCQ for definitions / choose-the-correct.
- Use "topic" matching a syllabus topic name when possible.
- Aim for 12–28 cards total (teach + check). Do not exceed 36.
- Do NOT create long word problems, exam-level sums, or written-sheet style questions.

JSON format:
{
  "chapter_title": "Exact chapter title",
  "cards": [
    {
      "step": 1,
      "type": "teach",
      "title": "Variables",
      "body": "In algebra, letters stand for numbers. We call these letters variables.",
      "example": "In 2a + 3, a is a variable.",
      "common_mistake": null,
      "topic": "Exact topic name or null"
    },
    {
      "step": 2,
      "type": "check",
      "title": "Quick check — variables",
      "topic": "Exact topic name or null",
      "questions": [
        {
          "question_type": "mcq",
          "question": "Which of these is a variable?",
          "options": ["7", "a", "12", "0"],
          "correct_index": 1,
          "correct_answer": null,
          "answer_format": null,
          "explanation": "a stands for a number — it is a variable."
        }
      ]
    },
    {
      "step": 3,
      "type": "teach",
      "title": "Squares — read the notation",
      "body": "3² means 3 × 3, not 3 × 2.",
      "example": "4² = 4 × 4 = 16.",
      "common_mistake": "Students sometimes compute n² as n × 2.",
      "topic": "Exact topic name or null"
    },
    {
      "step": 4,
      "type": "check",
      "title": "Quick check — square",
      "topic": "Exact topic name or null",
      "questions": [
        {
          "question_type": "fill_blank",
          "question": "5² = ____",
          "options": [],
          "correct_index": null,
          "correct_answer": "25",
          "answer_format": "integer",
          "explanation": "5² = 5 × 5 = 25."
        }
      ]
    }
  ]
}
PROMPT;
    }

    /**
     * @return array{cards: list<array<string, mixed>>, chapter_title: string, error: ?string}
     */
    public function preview(string $rawJson): array
    {
        try {
            $parsed = $this->parse($rawJson);
        } catch (InvalidArgumentException $e) {
            return [
                'chapter_title' => '',
                'cards' => [],
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'chapter_title' => '',
                'cards' => [],
                'error' => 'Could not read that JSON. Check it is valid concept-path JSON (cards with teach/check).',
            ];
        }

        return [
            'chapter_title' => $parsed['chapter_title'],
            'cards' => $parsed['cards'],
            'error' => null,
        ];
    }

    /**
     * @return array{chapter_title: string, cards: list<array<string, mixed>>, teach_count: int, check_count: int, question_count: int}
     */
    public function parse(string $rawJson): array
    {
        $rawJson = trim($rawJson);
        if ($rawJson === '') {
            throw new InvalidArgumentException('Paste the concept-path JSON first.');
        }

        if (str_starts_with($rawJson, '```')) {
            $rawJson = preg_replace('/^```(?:json)?\s*/i', '', $rawJson) ?? $rawJson;
            $rawJson = preg_replace('/\s*```$/', '', $rawJson) ?? $rawJson;
        }

        try {
            $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('JSON is not valid: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('JSON must be an object with a cards array.');
        }

        $cardsIn = $decoded['cards'] ?? $decoded['steps'] ?? null;
        if (! is_array($cardsIn) || $cardsIn === []) {
            throw new InvalidArgumentException('JSON must include a non-empty "cards" array.');
        }

        $cards = [];
        $teachCount = 0;
        $checkCount = 0;
        $questionCount = 0;

        foreach (array_values($cardsIn) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if (! in_array($type, ['teach', 'check'], true)) {
                throw new InvalidArgumentException('Card #'.($index + 1).' must have type "teach" or "check".');
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                throw new InvalidArgumentException('Card #'.($index + 1).' needs a title.');
            }

            $card = [
                'step' => (int) ($row['step'] ?? ($index + 1)),
                'type' => $type,
                'title' => Str::limit($title, 120, ''),
                'topic' => filled($row['topic'] ?? null) ? trim((string) $row['topic']) : null,
                'approved' => true,
            ];

            if ($type === 'teach') {
                $body = trim((string) ($row['body'] ?? ''));
                if ($body === '') {
                    throw new InvalidArgumentException('Teach card "'.$title.'" needs a body.');
                }
                $card['body'] = Str::limit($body, 2000, '');
                $card['example'] = filled($row['example'] ?? null) ? Str::limit(trim((string) $row['example']), 800, '') : null;
                $card['common_mistake'] = filled($row['common_mistake'] ?? null)
                    ? Str::limit(trim((string) $row['common_mistake']), 500, '')
                    : null;
                $teachCount++;
            } else {
                $questionsIn = $row['questions'] ?? [];
                if (! is_array($questionsIn) || $questionsIn === []) {
                    throw new InvalidArgumentException('Check card "'.$title.'" needs 1–3 questions.');
                }
                if (count($questionsIn) > 3) {
                    $questionsIn = array_slice($questionsIn, 0, 3);
                }

                $questions = [];
                foreach ($questionsIn as $qIndex => $q) {
                    if (! is_array($q)) {
                        continue;
                    }
                    $questions[] = $this->normalizeCheckQuestion($q, $title, $qIndex + 1);
                }

                if ($questions === []) {
                    throw new InvalidArgumentException('Check card "'.$title.'" has no usable questions.');
                }

                $card['questions'] = $questions;
                $questionCount += count($questions);
                $checkCount++;
            }

            $cards[] = $card;
        }

        if ($cards === []) {
            throw new InvalidArgumentException('No usable cards found in JSON.');
        }

        if ($teachCount === 0) {
            throw new InvalidArgumentException('Include at least one teach card.');
        }

        if ($checkCount === 0) {
            throw new InvalidArgumentException('Include at least one check card with practice questions.');
        }

        return [
            'chapter_title' => trim((string) ($decoded['chapter_title'] ?? $decoded['chapter'] ?? '')),
            'cards' => $cards,
            'teach_count' => $teachCount,
            'check_count' => $checkCount,
            'question_count' => $questionCount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    public function saveDraft(TextbookChapter $chapter, array $cards, ?string $chapterTitle = null): TextbookChapter
    {
        if ($cards === []) {
            throw new InvalidArgumentException('Nothing to save — preview cards first.');
        }

        // Re-validate through parse for consistency.
        $payload = [
            'chapter_title' => $chapterTitle ?: $chapter->title,
            'cards' => $cards,
        ];
        $normalized = $this->parse(json_encode($payload, JSON_THROW_ON_ERROR));

        $chapter->update([
            'concept_path_items' => [
                'chapter_title' => $normalized['chapter_title'] ?: $chapter->title,
                'cards' => $normalized['cards'],
                'teach_count' => $normalized['teach_count'],
                'check_count' => $normalized['check_count'],
                'question_count' => $normalized['question_count'],
                'saved_at' => now()->toIso8601String(),
            ],
            'concept_path_status' => ConceptPathStatus::DRAFT,
            'concept_path_approved_at' => null,
            'concept_path_approved_by' => null,
        ]);

        return $chapter->fresh();
    }

    public function approve(TextbookChapter $chapter, User $user): TextbookChapter
    {
        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = $items['cards'] ?? [];

        if (! is_array($cards) || $cards === []) {
            throw new InvalidArgumentException('Save a concept-path draft before approving.');
        }

        $included = array_values(array_filter(
            $cards,
            fn ($card) => is_array($card) && ($card['approved'] ?? true),
        ));

        if ($included === []) {
            throw new InvalidArgumentException('Tick at least one card to include before approving.');
        }

        $items['cards'] = array_values(array_map(function (array $card, int $index) {
            $card['step'] = $index + 1;
            $card['approved'] = true;

            return $card;
        }, $included, array_keys($included)));

        $chapter->update([
            'concept_path_items' => $items,
            'concept_path_status' => ConceptPathStatus::APPROVED,
            'concept_path_approved_at' => now(),
            'concept_path_approved_by' => $user->id,
        ]);

        return $chapter->fresh();
    }

    public function reset(TextbookChapter $chapter): TextbookChapter
    {
        $chapter->update([
            'concept_path_items' => null,
            'concept_path_status' => null,
            'concept_path_approved_at' => null,
            'concept_path_approved_by' => null,
        ]);

        return $chapter->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(TextbookChapter $chapter): array
    {
        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];
        $status = $chapter->concept_path_status;

        $prompt = '';
        if (filled($chapter->pdf_path)) {
            try {
                $prompt = $this->cursorPrompt($chapter);
            } catch (\Throwable $e) {
                report($e);
                $prompt = '';
            }
        }

        return [
            'status' => $status,
            'status_label' => ConceptPathStatus::label($status),
            'chapter_title' => $items['chapter_title'] ?? $chapter->title,
            'cards' => $cards,
            'teach_count' => (int) ($items['teach_count'] ?? collect($cards)->where('type', 'teach')->count()),
            'check_count' => (int) ($items['check_count'] ?? collect($cards)->where('type', 'check')->count()),
            'question_count' => (int) ($items['question_count'] ?? collect($cards)
                ->where('type', 'check')
                ->sum(fn ($c) => count($c['questions'] ?? []))),
            'approved_at' => $chapter->concept_path_approved_at?->toIso8601String(),
            'has_pdf' => filled($chapter->pdf_path),
            'prompt' => $prompt,
        ];
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    private function normalizeCheckQuestion(array $q, string $cardTitle, int $number): array
    {
        $qType = strtolower(trim((string) ($q['question_type'] ?? $q['type'] ?? 'mcq')));
        if (! in_array($qType, ['mcq', 'fill_blank', 'fill_in_blank'], true)) {
            $qType = 'mcq';
        }
        if ($qType === 'fill_in_blank') {
            $qType = 'fill_blank';
        }

        $stem = trim((string) ($q['question'] ?? $q['question_text'] ?? ''));
        if ($stem === '') {
            throw new InvalidArgumentException('Check "'.$cardTitle.'" question #'.$number.' needs question text.');
        }

        $explanation = filled($q['explanation'] ?? null) ? Str::limit(trim((string) $q['explanation']), 500, '') : null;

        if ($qType === 'fill_blank') {
            $answer = trim((string) ($q['correct_answer'] ?? ''));
            if ($answer === '') {
                throw new InvalidArgumentException('Fill-blank in "'.$cardTitle.'" question #'.$number.' needs correct_answer.');
            }

            $format = trim((string) ($q['answer_format'] ?? 'integer'));
            if (! in_array($format, ['integer', 'decimal', 'fraction', 'text'], true)) {
                $format = 'integer';
            }

            return [
                'question_type' => 'fill_blank',
                'question' => Str::limit($stem, 500, ''),
                'options' => [],
                'correct_index' => null,
                'correct_answer' => Str::limit($answer, 80, ''),
                'answer_format' => $format,
                'explanation' => $explanation,
            ];
        }

        $options = array_values(array_filter(
            array_map(fn ($opt) => trim((string) $opt), is_array($q['options'] ?? null) ? $q['options'] : []),
            fn (string $opt) => $opt !== '',
        ));

        if (count($options) < 2) {
            throw new InvalidArgumentException('MCQ in "'.$cardTitle.'" question #'.$number.' needs at least 2 options.');
        }

        $options = array_slice($options, 0, 4);
        while (count($options) < 4) {
            $options[] = '—';
        }

        $correctIndex = (int) ($q['correct_index'] ?? 0);
        if ($correctIndex < 0 || $correctIndex > 3) {
            $correctIndex = 0;
        }

        return [
            'question_type' => 'mcq',
            'question' => Str::limit($stem, 500, ''),
            'options' => $options,
            'correct_index' => $correctIndex,
            'correct_answer' => null,
            'answer_format' => null,
            'explanation' => $explanation,
        ];
    }
}
