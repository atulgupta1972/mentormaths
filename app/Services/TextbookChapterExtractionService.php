<?php

namespace App\Services;

use App\Models\TextbookChapter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;

class TextbookChapterExtractionService
{
    private const MAX_VISION_PAGES_PER_BATCH = 6;

    public function __construct(
        private PdfPageImageService $pageImageService,
    ) {}

    public function extract(TextbookChapter $chapter): array
    {
        @ini_set('memory_limit', '512M');

        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);

        if (! Storage::disk('public')->exists($chapter->pdf_path)) {
            throw new InvalidArgumentException('Chapter PDF file is missing.');
        }

        $pageTexts = $this->extractPageTexts($chapter->pdf_path);
        $batches = $this->pageTextBatches($pageTexts);
        $outputDirectory = 'temp/textbook-extraction/'.$chapter->id.'/'.now()->timestamp;
        $allPagePaths = [];

        if ($this->pageImageService->isAvailable()) {
            try {
                $allPagePaths = $this->pageImageService->renderPages($chapter->pdf_path, $outputDirectory);
            } catch (\Throwable $exception) {
                Log::warning('Textbook extraction: page images unavailable', [
                    'textbook_chapter_id' => $chapter->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $allItems = [];

        try {
            foreach ($batches as $batchIndex => $batchPages) {
                $batchItems = $this->extractBatch(
                    $chapter,
                    $batchPages,
                    $batchIndex + 1,
                    count($batches),
                    $allPagePaths,
                );

                $allItems = $this->mergeItems($allItems, $batchItems);
            }
        } finally {
            if ($outputDirectory !== '') {
                Storage::disk('public')->deleteDirectory($outputDirectory);
            }
        }

        if ($allItems === []) {
            throw new InvalidArgumentException('AI extraction found no usable questions.');
        }

        return $allItems;
    }

    /**
     * @param  list<array{page: int, text: string}>  $batchPages
     * @param  list<string>  $allPagePaths
     * @return list<array<string, mixed>>
     */
    private function extractBatch(
        TextbookChapter $chapter,
        array $batchPages,
        int $batchNumber,
        int $batchTotal,
        array $allPagePaths,
    ): array {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            throw new InvalidArgumentException('OPENAI_API_KEY is not configured on the server.');
        }

        $batchPageNumbers = array_column($batchPages, 'page');
        $imageParts = $this->buildVisionPartsForPages($batchPages, $allPagePaths);
        $prompt = $this->buildPrompt($chapter, $batchPages, $batchNumber, $batchTotal);

        $response = Http::withToken($apiKey)
            ->timeout(300)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.textbook_extraction_model', 'gpt-4o-mini'),
                'max_tokens' => (int) config('services.openai.textbook_extraction_max_tokens', 16384),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You extract school maths questions from NCERT-style textbook PDFs. Return strict JSON only. Never skip numbered questions in the requested page range.',
                    ],
                    [
                        'role' => 'user',
                        'content' => array_merge(
                            [['type' => 'text', 'text' => $prompt]],
                            $imageParts,
                        ),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('AI extraction failed: '.$response->body());
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new InvalidArgumentException('AI extraction returned an empty response.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $this->normalizeItems($payload['items'] ?? [], allowEmpty: true);
    }

    /**
     * @return list<array{page: int, text: string}>
     */
    private function extractPageTexts(string $pdfPath): array
    {
        $absolute = Storage::disk('public')->path($pdfPath);
        $parser = new Parser;
        $pdf = $parser->parseFile($absolute);
        $pages = $pdf->getPages();
        $result = [];

        foreach ($pages as $index => $page) {
            $text = trim((string) $page->getText());
            if ($text === '' || $this->isGraphPaperPage($text)) {
                continue;
            }

            $result[] = [
                'page' => $index + 1,
                'text' => $text,
            ];
        }

        if ($result === []) {
            throw new InvalidArgumentException('No readable text found in this PDF.');
        }

        return $result;
    }

    /**
     * @param  list<array{page: int, text: string}>  $pageTexts
     * @return list<list<array{page: int, text: string}>>
     */
    private function pageTextBatches(array $pageTexts): array
    {
        $pagesPerBatch = max(1, (int) config('services.openai.textbook_extraction_pages_per_batch', 5));

        return array_values(array_chunk($pageTexts, $pagesPerBatch));
    }

    private function isGraphPaperPage(string $text): bool
    {
        $normalized = strtolower(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return str_contains($normalized, 'graph paper')
            && mb_strlen($normalized) < 80;
    }

    /**
     * @param  list<array{page: int, text: string}>  $batchPages
     * @param  list<string>  $allPagePaths
     * @return list<array{type: string, image_url: array{url: string}}>
     */
    private function buildVisionPartsForPages(array $batchPages, array $allPagePaths): array
    {
        if ($allPagePaths === []) {
            return [];
        }

        $pagesToRender = $this->selectVisionPages($batchPages);
        $parts = [];

        foreach ($pagesToRender as $pageNumber) {
            $path = $allPagePaths[$pageNumber - 1] ?? null;
            if (! $path || ! Storage::disk('public')->exists($path)) {
                continue;
            }

            $absolute = Storage::disk('public')->path($path);
            $mime = mime_content_type($absolute) ?: 'image/png';
            $encoded = base64_encode((string) file_get_contents($absolute));

            $parts[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mime};base64,{$encoded}",
                ],
            ];
        }

        return $parts;
    }

    /**
     * @param  list<array{page: int, text: string}>  $pageTexts
     * @return list<int>
     */
    private function selectVisionPages(array $pageTexts): array
    {
        $selected = [];

        foreach ($pageTexts as $row) {
            $text = $row['text'];
            if (preg_match('/\b(Fig\.|Figure|Example\s+\d+|Exercise:|End-of-Chapter|\?\s*$)/i', $text)) {
                $selected[] = $row['page'];
            }
        }

        if ($selected === []) {
            $selected = array_column($pageTexts, 'page');
        }

        $selected = array_values(array_unique($selected));
        sort($selected);

        return array_slice($selected, 0, self::MAX_VISION_PAGES_PER_BATCH);
    }

    /**
     * @param  list<array{page: int, text: string}>  $batchPages
     */
    private function buildPrompt(
        TextbookChapter $chapter,
        array $batchPages,
        int $batchNumber,
        int $batchTotal,
    ): string {
        $grade = $chapter->textbook?->gradeLevel?->name ?? 'Class';
        $book = $chapter->textbook?->name ?? 'Textbook';
        $chapterLabel = "Chapter {$chapter->chapter_number} — {$chapter->title}";
        $firstPage = $batchPages[0]['page'];
        $lastPage = $batchPages[array_key_last($batchPages)]['page'];

        $textBundle = collect($batchPages)
            ->map(fn (array $row) => "=== PAGE {$row['page']} ===\n{$row['text']}")
            ->implode("\n\n");

        return <<<PROMPT
Extract gradable maths questions from this textbook chapter PDF.

Context:
- Class: {$grade}
- Book: {$book}
- {$chapterLabel}
- Batch {$batchNumber} of {$batchTotal} — PDF pages {$firstPage}–{$lastPage} only

CRITICAL: Extract EVERY qualifying item on these pages. Do not stop after a few items. Do not merge separate questions. If a page has Example 3 and Exercise: with three parts, return each gradable part separately.

INCLUDE (on these pages only):
- Every worked example labelled "Example 1", "Example 2", etc.
- Every inline prompt starting with "Exercise:"
- Every numbered question under "End-of-Chapter Exercises" (including sub-parts a, b, c when gradable)

EXCLUDE:
- "Think and Reflect" discussion prompts
- Chapter summary / theory-only paragraphs
- Graph paper pages

For diagram-based questions (Fig., figures, dot patterns, fractal stages):
- Use the page images to read the diagram
- Set needs_diagram true and describe the figure in the question text (e.g. "In the figure, …")

For each item return:
- id: stable string like "ex-1", "inline-2", "eoc-3a" (unique within the whole chapter)
- kind: "example" | "inline_exercise" | "end_exercise"
- label: display label like "Example 1" or "End Q3"
- source_page: page number from the PDF text markers
- question_text: clean student-facing wording (fix broken subscripts like t_n)
- correct_answer: final answer (AI may infer when not printed — show working in explanation)
- answer_format: "integer" | "decimal" | "fraction" | "text"
- explanation: brief marking notes / working
- needs_diagram: boolean
- include_in_mcq: true
- include_in_written: true
- mcq_options: exactly 4 options with text + is_correct (one true), plausible distractors

Return JSON: {"items": [ ... ]}

PDF text (page markers):
{$textBundle}
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array<string, mixed>>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeItems(array $existing, array $incoming): array
    {
        $seen = [];
        foreach ($existing as $item) {
            $seen[$this->itemKey($item)] = true;
        }

        foreach ($incoming as $item) {
            $key = $this->itemKey($item);
            if (isset($seen[$key])) {
                continue;
            }

            $existing[] = $item;
            $seen[$key] = true;
        }

        usort($existing, function (array $a, array $b): int {
            $pageCompare = ($a['source_page'] ?? 0) <=> ($b['source_page'] ?? 0);
            if ($pageCompare !== 0) {
                return $pageCompare;
            }

            return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });

        return array_values($existing);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemKey(array $item): string
    {
        $id = trim((string) ($item['id'] ?? ''));
        if ($id !== '') {
            return 'id:'.$id;
        }

        return 'label:'.($item['source_page'] ?? 0).':'.strtolower(trim((string) ($item['label'] ?? '')));
    }

    /**
     * @param  mixed  $rawItems
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(mixed $rawItems, bool $allowEmpty = false): array
    {
        if (! is_array($rawItems)) {
            return [];
        }

        $items = [];
        $index = 0;

        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $index++;
            $questionText = trim((string) ($item['question_text'] ?? ''));
            if ($questionText === '') {
                continue;
            }

            $options = collect($item['mcq_options'] ?? [])
                ->filter(fn ($opt) => is_array($opt) && filled($opt['text'] ?? null))
                ->take(4)
                ->values()
                ->all();

            $items[] = [
                'id' => (string) ($item['id'] ?? "item-{$index}"),
                'kind' => (string) ($item['kind'] ?? 'end_exercise'),
                'label' => (string) ($item['label'] ?? "Q{$index}"),
                'source_page' => (int) ($item['source_page'] ?? 0),
                'question_text' => $questionText,
                'correct_answer' => trim((string) ($item['correct_answer'] ?? '')),
                'answer_format' => (string) ($item['answer_format'] ?? 'text'),
                'explanation' => trim((string) ($item['explanation'] ?? '')),
                'needs_diagram' => (bool) ($item['needs_diagram'] ?? false),
                'include_in_mcq' => array_key_exists('include_in_mcq', $item)
                    ? (bool) $item['include_in_mcq']
                    : true,
                'include_in_written' => array_key_exists('include_in_written', $item)
                    ? (bool) $item['include_in_written']
                    : true,
                'approved' => true,
                'mcq_options' => $options,
            ];
        }

        if ($items === [] && ! $allowEmpty) {
            throw new InvalidArgumentException('AI extraction found no usable questions.');
        }

        return $items;
    }
}
