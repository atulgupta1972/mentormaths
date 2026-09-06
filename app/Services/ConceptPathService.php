<?php

namespace App\Services;

use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ConceptPathStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ConceptPathService
{
    public function __construct(
        private PdfPageImageService $pageImageService,
    ) {}

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
3) "angle_map" — OPTIONAL last card for Parallel Lines / transversal chapters only.
   Same fixed figure with angles 1–8. Each prompt highlights one angle; the student taps the matching angle on the figure.
   Mentormaths renders an interactive SVG board (do not invent ASCII art). Set figure_page to null.

Pedagogy rules:
- Cover the WHOLE chapter concept flow in teaching order (definitions → notation → building blocks → common traps → simple use).
- One idea per teach card. Keep body to 2–5 short sentences. Use class-appropriate language.
- After every 1–2 teach cards, add a check card.
- Explicitly include “common mistake” teach cards where students confuse notation (e.g. 3² vs 3×2, like terms, signs).
- Check questions must be EASY — prove understanding, not assess the chapter.
- Prefer fill_blank for simple numeric answers; MCQ for definitions / choose-the-correct.
- Use "topic" matching a syllabus topic name when possible.
- When a textbook figure is essential, mention it by name in example/body (e.g. "See Fig 5.14") AND set "figure_page" to the 1-based PDF page number where that figure appears. Do NOT invent ASCII art — Mentormaths will auto-attach that PDF page for cropping.
- If no figure is needed, set "figure_page": null.
- Aim for 12–28 cards total (teach + check). Do not exceed 36.
- Do NOT create long word problems, exam-level sums, or written-sheet style questions.
- For Parallel and Intersecting Lines chapters: AFTER the teach/check flow, add ONE final "angle_map" card with 10–16 easy tap prompts covering corresponding, vertically opposite, adjacent/linear pair, alternate interior, alternate exterior, and co-interior.

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
      "topic": "Exact topic name or null",
      "figure_page": null
    },
    {
      "step": 2,
      "type": "check",
      "title": "Quick check — variables",
      "topic": "Exact topic name or null",
      "figure_page": null,
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
      "topic": "Exact topic name or null",
      "figure_page": null
    },
    {
      "step": 4,
      "type": "check",
      "title": "Quick check — square",
      "topic": "Exact topic name or null",
      "figure_page": null,
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
    },
    {
      "step": 5,
      "type": "teach",
      "title": "Transversal",
      "body": "A line that crosses two other lines at different points is a transversal.",
      "example": "See Fig 5.14 — line t crosses lines l and m.",
      "common_mistake": "Students confuse the transversal with one of the two lines being crossed.",
      "topic": "Pairs of Lines",
      "figure_page": 14
    },
    {
      "step": 24,
      "type": "angle_map",
      "title": "Tap the matching angle",
      "body": "Same figure stays on screen. An angle is highlighted — tap the matching angle.",
      "topic": "Parallel Lines & Transversal",
      "figure_page": null,
      "prompts": [
        {
          "relation": "corresponding",
          "prompt": "Corresponding angle of ∠1 is…",
          "highlight": 1,
          "correct": 5,
          "explanation": "∠1 and ∠5 sit in matching positions — corresponding angles."
        },
        {
          "relation": "vertically_opposite",
          "prompt": "Vertically opposite angle of ∠1 is…",
          "highlight": 1,
          "correct": 3,
          "explanation": "Vertically opposite angles face each other across the intersection."
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
        $angleMapCount = 0;
        $questionCount = 0;

        foreach (array_values($cardsIn) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if (! in_array($type, ['teach', 'check', 'angle_map'], true)) {
                throw new InvalidArgumentException('Card #'.($index + 1).' must have type "teach", "check", or "angle_map".');
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
                'figure_page' => $this->normalizeFigurePage($row['figure_page'] ?? null),
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
            } elseif ($type === 'angle_map') {
                $body = trim((string) ($row['body'] ?? 'Same figure stays on screen. Tap the matching angle.'));
                $card['body'] = Str::limit($body !== '' ? $body : 'Same figure stays on screen. Tap the matching angle.', 800, '');
                $card['figure_page'] = null;
                $prompts = $this->normalizeAngleMapPrompts($row['prompts'] ?? [], $title);
                $card['prompts'] = $prompts;
                $questionCount += count($prompts);
                $angleMapCount++;
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

        if ($checkCount === 0 && $angleMapCount === 0) {
            throw new InvalidArgumentException('Include at least one check card or angle_map practice card.');
        }

        return [
            'chapter_title' => trim((string) ($decoded['chapter_title'] ?? $decoded['chapter'] ?? '')),
            'cards' => $cards,
            'teach_count' => $teachCount,
            'check_count' => $checkCount,
            'angle_map_count' => $angleMapCount,
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

        // Keep uploaded figures that the browser/client still references.
        foreach ($normalized['cards'] as $index => $card) {
            $incomingPath = $cards[$index]['diagram_path'] ?? null;
            if (is_string($incomingPath) && $this->isOwnedDiagramPath($chapter, $incomingPath)) {
                $normalized['cards'][$index]['diagram_path'] = $incomingPath;
            }
            $incomingPage = $this->normalizeFigurePage($cards[$index]['figure_page'] ?? $card['figure_page'] ?? null);
            if ($incomingPage !== null) {
                $normalized['cards'][$index]['figure_page'] = $incomingPage;
            }
        }

        $chapter->update([
            'concept_path_items' => [
                'chapter_title' => $normalized['chapter_title'] ?: $chapter->title,
                'cards' => $normalized['cards'],
                'teach_count' => $normalized['teach_count'],
                'check_count' => $normalized['check_count'],
                'angle_map_count' => $normalized['angle_map_count'] ?? 0,
                'question_count' => $normalized['question_count'],
                'saved_at' => now()->toIso8601String(),
            ],
            'concept_path_status' => ConceptPathStatus::DRAFT,
            'concept_path_approved_at' => null,
            'concept_path_approved_by' => null,
        ]);

        $chapter = $chapter->fresh();

        // Like MCQ zip pages: auto-attach the textbook PDF page named by figure_page.
        try {
            $this->autoAttachFigurePages($chapter);
        } catch (\Throwable $e) {
            report($e);
        }

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
        $this->deleteAllDiagrams($chapter);

        $chapter->update([
            'concept_path_items' => null,
            'concept_path_status' => null,
            'concept_path_approved_at' => null,
            'concept_path_approved_by' => null,
        ]);

        return $chapter->fresh();
    }

    public function replaceCardDiagram(TextbookChapter $chapter, int $cardIndex, UploadedFile $image): TextbookChapter
    {
        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];

        if (! isset($cards[$cardIndex]) || ! is_array($cards[$cardIndex])) {
            throw new InvalidArgumentException('Concept card not found.');
        }

        $this->deleteDiagramPath($cards[$cardIndex]['diagram_path'] ?? null);

        $extension = strtolower($image->getClientOriginalExtension() ?: 'png');
        if (! in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            $extension = 'png';
        }

        $path = $image->storeAs(
            $this->diagramDirectory($chapter),
            Str::uuid()->toString().'.'.$extension,
            'public',
        );

        $cards[$cardIndex]['diagram_path'] = $path;
        $items['cards'] = $cards;

        $chapter->update(['concept_path_items' => $items]);

        return $chapter->fresh();
    }

    public function attachPdfPageAsDiagram(TextbookChapter $chapter, int $cardIndex, int $pageNumber): TextbookChapter
    {
        $pages = $this->chapterPdfPages($chapter);
        $match = collect($pages)->firstWhere('page', $pageNumber);
        if (! $match) {
            throw new InvalidArgumentException("PDF page {$pageNumber} is not available for this chapter.");
        }

        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];
        if (! isset($cards[$cardIndex]) || ! is_array($cards[$cardIndex])) {
            throw new InvalidArgumentException('Concept card not found.');
        }

        $source = Storage::disk('public')->path($match['path']);
        if (! is_file($source)) {
            throw new InvalidArgumentException("PDF page {$pageNumber} file is missing. Try Refresh PDF pages.");
        }

        $this->deleteDiagramPath($cards[$cardIndex]['diagram_path'] ?? null);

        $destination = $this->diagramDirectory($chapter).'/'.Str::uuid()->toString().'.png';
        Storage::disk('public')->put($destination, file_get_contents($source));

        $cards[$cardIndex]['diagram_path'] = $destination;
        $cards[$cardIndex]['figure_page'] = $pageNumber;
        $items['cards'] = $cards;
        $chapter->update(['concept_path_items' => $items]);

        return $chapter->fresh();
    }

    /**
     * Auto-attach chapter PDF pages for cards that declare figure_page and still lack a diagram.
     *
     * @return int Number of cards that received a page image
     */
    public function autoAttachFigurePages(TextbookChapter $chapter): int
    {
        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];
        if ($cards === []) {
            return 0;
        }

        $attached = 0;
        foreach ($cards as $index => $card) {
            if (! is_array($card)) {
                continue;
            }
            if (filled($card['diagram_path'] ?? null)) {
                continue;
            }
            $page = $this->normalizeFigurePage($card['figure_page'] ?? null);
            if ($page === null) {
                continue;
            }

            try {
                $chapter = $this->attachPdfPageAsDiagram($chapter, $index, $page);
                $attached++;
                $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
                $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $attached;
    }

    /**
     * @return list<array{page: int, path: string, url: string}>
     */
    public function chapterPdfPages(TextbookChapter $chapter, bool $forceRefresh = false): array
    {
        if (! filled($chapter->pdf_path) || ! Storage::disk('public')->exists($chapter->pdf_path)) {
            return [];
        }

        $directory = $this->pageCacheDirectory($chapter);
        if ($forceRefresh) {
            Storage::disk('public')->deleteDirectory($directory);
        }

        $existing = $this->listCachedPages($directory);
        if ($existing === []) {
            if (! $this->pageImageService->isAvailable()) {
                return [];
            }
            $existing = $this->pageImageService->renderPages($chapter->pdf_path, $directory);
        }

        return collect($existing)
            ->values()
            ->map(function (string $path, int $index) {
                $page = $index + 1;
                if (preg_match('/page-(\d+)\.png$/i', $path, $m)) {
                    $page = (int) $m[1];
                }

                return [
                    'page' => $page,
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                ];
            })
            ->sortBy('page')
            ->values()
            ->all();
    }

    /**
     * Fast: return already-rendered chapter PDF page thumbnails without re-rendering.
     *
     * @return list<array{page: int, path: string, url: string}>
     */
    public function cachedChapterPdfPages(TextbookChapter $chapter): array
    {
        $directory = $this->pageCacheDirectory($chapter);
        $existing = $this->listCachedPages($directory);

        return collect($existing)
            ->values()
            ->map(function (string $path, int $index) {
                $page = $index + 1;
                if (preg_match('/page-(\d+)\.png$/i', $path, $m)) {
                    $page = (int) $m[1];
                }

                return [
                    'page' => $page,
                    'path' => $path,
                    'url' => Storage::disk('public')->url($path),
                ];
            })
            ->sortBy('page')
            ->values()
            ->all();
    }

    public function clearChapterPageCache(TextbookChapter $chapter): void
    {
        Storage::disk('public')->deleteDirectory($this->pageCacheDirectory($chapter));
    }

    /**
     * @return list<string>
     */
    private function listCachedPages(string $directory): array
    {
        if (! Storage::disk('public')->exists($directory)) {
            return [];
        }

        return collect(Storage::disk('public')->files($directory))
            ->filter(fn (string $file) => (bool) preg_match('/page-\d+\.png$/i', $file))
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    private function pageCacheDirectory(TextbookChapter $chapter): string
    {
        return 'textbook-chapter-pages/'.$chapter->id;
    }

    private function normalizeFigurePage(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $page = (int) $value;

        return $page > 0 ? $page : null;
    }

    public function removeCardDiagram(TextbookChapter $chapter, int $cardIndex): TextbookChapter
    {
        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];

        if (! isset($cards[$cardIndex]) || ! is_array($cards[$cardIndex])) {
            throw new InvalidArgumentException('Concept card not found.');
        }

        $this->deleteDiagramPath($cards[$cardIndex]['diagram_path'] ?? null);
        unset($cards[$cardIndex]['diagram_path'], $cards[$cardIndex]['diagram_url']);
        $items['cards'] = $cards;

        $chapter->update(['concept_path_items' => $items]);

        return $chapter->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return list<array<string, mixed>>
     */
    public function withDiagramUrls(array $cards): array
    {
        return array_values(array_map(function ($card) {
            if (! is_array($card)) {
                return $card;
            }

            $path = $card['diagram_path'] ?? null;
            $card['diagram_url'] = (is_string($path) && $path !== '' && Storage::disk('public')->exists($path))
                ? Storage::disk('public')->url($path)
                : null;

            return $card;
        }, $cards));
    }

    private function diagramDirectory(TextbookChapter $chapter): string
    {
        return 'concept-path-diagrams/'.$chapter->id;
    }

    private function isOwnedDiagramPath(TextbookChapter $chapter, string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return str_starts_with($path, $this->diagramDirectory($chapter).'/')
            && ! str_contains($path, '..');
    }

    private function deleteDiagramPath(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function deleteAllDiagrams(TextbookChapter $chapter): void
    {
        Storage::disk('public')->deleteDirectory($this->diagramDirectory($chapter));
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

        $cards = $this->withDiagramUrls($cards);

        return [
            'status' => $status,
            'status_label' => ConceptPathStatus::label($status),
            'chapter_title' => $items['chapter_title'] ?? $chapter->title,
            'cards' => $cards,
            'teach_count' => (int) ($items['teach_count'] ?? collect($cards)->where('type', 'teach')->count()),
            'check_count' => (int) ($items['check_count'] ?? collect($cards)->where('type', 'check')->count()),
            'angle_map_count' => (int) ($items['angle_map_count'] ?? collect($cards)->where('type', 'angle_map')->count()),
            'question_count' => (int) ($items['question_count'] ?? (
                collect($cards)->where('type', 'check')->sum(fn ($c) => count($c['questions'] ?? []))
                + collect($cards)->where('type', 'angle_map')->sum(fn ($c) => count($c['prompts'] ?? []))
            )),
            'has_angle_map' => collect($cards)->contains(fn ($c) => is_array($c) && ($c['type'] ?? '') === 'angle_map'),
            'approved_at' => $chapter->concept_path_approved_at?->toIso8601String(),
            'has_pdf' => filled($chapter->pdf_path),
            'prompt' => $prompt,
        ];
    }

    /**
     * Append the interactive 1–8 angle-map practice card at the end of the draft path.
     */
    public function appendAngleMapPractice(TextbookChapter $chapter): TextbookChapter
    {
        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];

        if ($cards === []) {
            throw new InvalidArgumentException('Save or preview teach/check cards first, then add the angle-map practice at the end.');
        }

        $already = collect($cards)->contains(fn ($card) => is_array($card) && ($card['type'] ?? '') === 'angle_map');
        if ($already) {
            throw new InvalidArgumentException('This concept path already has an angle-map practice card.');
        }

        $cards[] = $this->defaultAngleMapCard(count($cards) + 1);

        return $this->saveDraft(
            $chapter,
            $cards,
            is_string($items['chapter_title'] ?? null) ? $items['chapter_title'] : $chapter->title,
        );
    }

    /**
     * Fixed Fig-5.14 style board: angles 1–8 for transversal practice.
     *
     * @return array<string, mixed>
     */
    public function defaultAngleMapCard(int $step = 1): array
    {
        return [
            'step' => $step,
            'type' => 'angle_map',
            'title' => 'Tap the matching angle',
            'body' => 'Same figure stays on screen. One angle is highlighted — tap the angle that matches the prompt.',
            'topic' => 'Parallel Lines & Transversal',
            'figure_page' => null,
            'approved' => true,
            'prompts' => [
                ['relation' => 'corresponding', 'prompt' => 'Corresponding angle of ∠1 is…', 'highlight' => 1, 'correct' => 5, 'explanation' => '∠1 and ∠5 are in matching positions — corresponding angles.'],
                ['relation' => 'vertically_opposite', 'prompt' => 'Vertically opposite angle of ∠1 is…', 'highlight' => 1, 'correct' => 3, 'explanation' => 'Vertically opposite angles face each other across the crossing.'],
                ['relation' => 'adjacent', 'prompt' => 'An adjacent angle that forms a linear pair with ∠1 is…', 'highlight' => 1, 'correct' => 2, 'explanation' => '∠1 and ∠2 are adjacent on a straight line — a linear pair (sum 180°).'],
                ['relation' => 'corresponding', 'prompt' => 'Corresponding angle of ∠2 is…', 'highlight' => 2, 'correct' => 6, 'explanation' => '∠2 and ∠6 are corresponding.'],
                ['relation' => 'vertically_opposite', 'prompt' => 'Vertically opposite angle of ∠2 is…', 'highlight' => 2, 'correct' => 4, 'explanation' => '∠2 and ∠4 are vertically opposite.'],
                ['relation' => 'alternate_interior', 'prompt' => 'Alternate interior angle of ∠3 is…', 'highlight' => 3, 'correct' => 5, 'explanation' => '∠3 and ∠5 are on opposite sides of the transversal, between the two lines.'],
                ['relation' => 'alternate_interior', 'prompt' => 'Alternate interior angle of ∠4 is…', 'highlight' => 4, 'correct' => 6, 'explanation' => '∠4 and ∠6 are alternate interior angles.'],
                ['relation' => 'co_interior', 'prompt' => 'Co-interior (same-side interior) angle of ∠3 is…', 'highlight' => 3, 'correct' => 6, 'explanation' => '∠3 and ∠6 are interior angles on the same side of the transversal — they add to 180° when lines are parallel.'],
                ['relation' => 'co_interior', 'prompt' => 'Co-interior (same-side interior) angle of ∠4 is…', 'highlight' => 4, 'correct' => 5, 'explanation' => '∠4 and ∠5 are co-interior angles.'],
                ['relation' => 'alternate_exterior', 'prompt' => 'Alternate exterior angle of ∠1 is…', 'highlight' => 1, 'correct' => 7, 'explanation' => '∠1 and ∠7 are on opposite sides of the transversal, outside the two lines.'],
                ['relation' => 'alternate_exterior', 'prompt' => 'Alternate exterior angle of ∠2 is…', 'highlight' => 2, 'correct' => 8, 'explanation' => '∠2 and ∠8 are alternate exterior angles.'],
                ['relation' => 'corresponding', 'prompt' => 'Corresponding angle of ∠4 is…', 'highlight' => 4, 'correct' => 8, 'explanation' => '∠4 and ∠8 are corresponding.'],
                ['relation' => 'adjacent', 'prompt' => 'An adjacent linear-pair angle of ∠6 is…', 'highlight' => 6, 'correct' => 5, 'explanation' => '∠5 and ∠6 form a linear pair on line m.'],
                ['relation' => 'vertically_opposite', 'prompt' => 'Vertically opposite angle of ∠6 is…', 'highlight' => 6, 'correct' => 8, 'explanation' => '∠6 and ∠8 are vertically opposite.'],
            ],
        ];
    }

    /**
     * @param  mixed  $promptsIn
     * @return list<array{relation: string, prompt: string, highlight: int, correct: int, explanation: ?string}>
     */
    private function normalizeAngleMapPrompts(mixed $promptsIn, string $cardTitle): array
    {
        if (! is_array($promptsIn) || $promptsIn === []) {
            throw new InvalidArgumentException('Angle-map card "'.$cardTitle.'" needs a non-empty prompts array.');
        }

        $allowedRelations = [
            'corresponding',
            'vertically_opposite',
            'adjacent',
            'linear_pair',
            'alternate_interior',
            'alternate_exterior',
            'co_interior',
        ];

        $prompts = [];
        foreach (array_values($promptsIn) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $promptText = trim((string) ($row['prompt'] ?? $row['question'] ?? ''));
            if ($promptText === '') {
                throw new InvalidArgumentException('Angle-map "'.$cardTitle.'" prompt #'.($index + 1).' needs prompt text.');
            }

            $highlight = (int) ($row['highlight'] ?? $row['from'] ?? 0);
            $correct = (int) ($row['correct'] ?? $row['answer'] ?? $row['to'] ?? 0);
            if ($highlight < 1 || $highlight > 8 || $correct < 1 || $correct > 8) {
                throw new InvalidArgumentException('Angle-map "'.$cardTitle.'" prompt #'.($index + 1).' needs highlight and correct as integers 1–8.');
            }
            if ($highlight === $correct) {
                throw new InvalidArgumentException('Angle-map "'.$cardTitle.'" prompt #'.($index + 1).' cannot have the same highlight and correct angle.');
            }

            $relation = strtolower(trim((string) ($row['relation'] ?? 'corresponding')));
            $relation = str_replace([' ', '-'], '_', $relation);
            if ($relation === 'linear_pair') {
                $relation = 'adjacent';
            }
            if (! in_array($relation, $allowedRelations, true)) {
                $relation = 'corresponding';
            }

            $prompts[] = [
                'relation' => $relation,
                'prompt' => Str::limit($promptText, 200, ''),
                'highlight' => $highlight,
                'correct' => $correct,
                'explanation' => filled($row['explanation'] ?? null)
                    ? Str::limit(trim((string) $row['explanation']), 400, '')
                    : null,
            ];
        }

        if ($prompts === []) {
            throw new InvalidArgumentException('Angle-map card "'.$cardTitle.'" has no usable prompts.');
        }

        if (count($prompts) > 24) {
            $prompts = array_slice($prompts, 0, 24);
        }

        return $prompts;
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
