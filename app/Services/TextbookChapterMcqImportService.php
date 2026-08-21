<?php

namespace App\Services;

use App\Models\Question;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TextbookChapterMcqImportService
{
    public function __construct(
        private McqImportService $mcqImport,
        private TextbookMcqSetPlanService $setPlanService,
        private QuestionZipImportService $zipImport,
    ) {}

    /**
     * @return array{items: list<array<string, mixed>>, payload: array<string, mixed>}
     */
    public function parsePayload(string $json): array
    {
        $payload = $this->decodePayload($json);
        $items = $this->parseToItemsFromPayload($payload);

        return [
            'items' => $items,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseToItems(string $json): array
    {
        return $this->parsePayload($json)['items'];
    }

    public function import(TextbookChapter $chapter, string $json): TextbookChapter
    {
        $parsed = $this->parsePayload($json);
        $items = $parsed['items'];
        $setPlan = $this->setPlanService->defaultPlan($chapter, count($items));

        $chapter->update([
            'extraction_items' => $items,
            'mcq_set_plan' => $setPlan,
            'status' => TextbookChapter::STATUS_REVIEW,
            'extracted_at' => now(),
            'extraction_error' => null,
        ]);

        return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']);
    }

    /**
     * @return array{chapter: TextbookChapter, question_count: int, diagram_count: int}
     */
    public function importZip(TextbookChapter $chapter, UploadedFile $zip): array
    {
        $this->deleteStagingDiagrams($chapter);

        $extracted = $this->zipImport->extract($zip);

        try {
            if ($extracted['type'] !== QuestionZipImportService::TYPE_MCQ) {
                throw new InvalidArgumentException('Zip must contain MCQ questions (options + correct_index).');
            }

            $items = [];
            $diagramCount = 0;

            foreach ($extracted['items'] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $diagramSource = $extracted['diagram_paths'][$index] ?? null;
                $diagramFile = trim((string) ($row['diagram_file'] ?? $row['chart_file'] ?? $row['diagram'] ?? ''));
                $stagingPath = null;

                if ($diagramSource && is_file($diagramSource)) {
                    $stagingPath = $this->persistStagingDiagram($chapter, $diagramSource);
                    $diagramCount++;
                }

                $normalized = $this->normalizeImportedRow($row, $index, $stagingPath, $diagramFile !== '' ? $diagramFile : null);
                if (trim((string) ($normalized['question_text'] ?? '')) === '') {
                    continue;
                }

                $items[] = $normalized;
            }

            if ($items === []) {
                throw new InvalidArgumentException('Could not parse any questions from the zip JSON.');
            }

            $setPlan = $this->setPlanService->defaultPlan($chapter, count($items));

            $chapter->update([
                'extraction_items' => $items,
                'mcq_set_plan' => $setPlan,
                'status' => TextbookChapter::STATUS_REVIEW,
                'extracted_at' => now(),
                'extraction_error' => null,
            ]);

            return [
                'chapter' => $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']),
                'question_count' => count($items),
                'diagram_count' => $diagramCount,
            ];
        } finally {
            if (is_dir($extracted['temp_dir'])) {
                File::deleteDirectory($extracted['temp_dir']);
            }
        }
    }

    /**
     * @return array{chapter: TextbookChapter, added_count: int, total_count: int}
     */
    public function append(TextbookChapter $chapter, string $json): array
    {
        $incoming = $this->parseToItems($json);
        $existing = $chapter->extraction_items ?? [];
        $offset = count($existing);

        $mergedIncoming = [];
        foreach ($incoming as $index => $item) {
            $item['id'] = 'mcq-'.($offset + $index + 1);
            $topic = trim((string) ($item['topic'] ?? ''));
            $item['label'] = $topic !== '' ? $topic.' · Q'.($offset + $index + 1) : 'Q'.($offset + $index + 1);
            $mergedIncoming[] = $item;
        }

        $merged = array_values(array_merge($existing, $mergedIncoming));
        $setPlan = $this->extendSetPlan($chapter, count($merged));

        $chapter->update([
            'extraction_items' => $merged,
            'mcq_set_plan' => $setPlan,
            'extracted_at' => now(),
            'extraction_error' => null,
        ]);

        return [
            'chapter' => $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']),
            'added_count' => count($mergedIncoming),
            'total_count' => count($merged),
        ];
    }

    /**
     * @return array{chapter: TextbookChapter, added_count: int, total_count: int, diagram_count: int}
     */
    public function appendZip(TextbookChapter $chapter, UploadedFile $zip): array
    {
        $extracted = $this->zipImport->extract($zip);

        try {
            if ($extracted['type'] !== QuestionZipImportService::TYPE_MCQ) {
                throw new InvalidArgumentException('Zip must contain MCQ questions (options + correct_index).');
            }

            $existing = $chapter->extraction_items ?? [];
            $offset = count($existing);
            $incoming = [];
            $diagramCount = 0;

            foreach ($extracted['items'] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $diagramSource = $extracted['diagram_paths'][$index] ?? null;
                $diagramFile = trim((string) ($row['diagram_file'] ?? $row['chart_file'] ?? $row['diagram'] ?? ''));
                $stagingPath = null;

                if ($diagramSource && is_file($diagramSource)) {
                    $stagingPath = $this->persistStagingDiagram($chapter, $diagramSource);
                    $diagramCount++;
                }

                $normalized = $this->normalizeImportedRow(
                    $row,
                    $offset + $index,
                    $stagingPath,
                    $diagramFile !== '' ? $diagramFile : null,
                );

                if (trim((string) ($normalized['question_text'] ?? '')) === '') {
                    continue;
                }

                $incoming[] = $normalized;
            }

            if ($incoming === []) {
                throw new InvalidArgumentException('Could not parse any questions from the zip JSON.');
            }

            $merged = array_values(array_merge($existing, $incoming));
            $setPlan = $this->extendSetPlan($chapter, count($merged));

            $chapter->update([
                'extraction_items' => $merged,
                'mcq_set_plan' => $setPlan,
                'extracted_at' => now(),
                'extraction_error' => null,
            ]);

            return [
                'chapter' => $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']),
                'added_count' => count($incoming),
                'total_count' => count($merged),
                'diagram_count' => $diagramCount,
            ];
        } finally {
            if (is_dir($extracted['temp_dir'])) {
                File::deleteDirectory($extracted['temp_dir']);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extendSetPlan(TextbookChapter $chapter, int $questionCount): array
    {
        $plan = $chapter->mcq_set_plan ?? [];

        if ($plan === []) {
            return $this->setPlanService->defaultPlan($chapter, $questionCount);
        }

        $last = count($plan) - 1;
        $plan[$last]['q_to'] = $questionCount;

        return $plan;
    }

    public function deleteStagingDiagrams(TextbookChapter $chapter): void
    {
        Storage::disk('public')->deleteDirectory($this->stagingDiagramDirectory($chapter));
    }

    public function replaceItemDiagram(TextbookChapter $chapter, int $itemIndex, UploadedFile $image): TextbookChapter
    {
        $items = $chapter->extraction_items ?? [];
        if (! isset($items[$itemIndex])) {
            throw new InvalidArgumentException('Question not found.');
        }

        $this->deleteStagingPath($items[$itemIndex]['diagram_staging_path'] ?? null);

        $items[$itemIndex]['diagram_staging_path'] = $this->persistStagingDiagram(
            $chapter,
            $image->getRealPath() ?: $image->path(),
            strtolower($image->getClientOriginalExtension() ?: 'png'),
        );
        $items[$itemIndex]['needs_diagram'] = true;

        $chapter->update(['extraction_items' => $items]);

        if ($chapter->status === TextbookChapter::STATUS_PUBLISHED) {
            $this->syncPublishedQuestionDiagram($chapter, $itemIndex, $items[$itemIndex]['diagram_staging_path']);
        }

        return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']);
    }

    public function removeItemDiagram(TextbookChapter $chapter, int $itemIndex): TextbookChapter
    {
        $items = $chapter->extraction_items ?? [];
        if (! isset($items[$itemIndex])) {
            throw new InvalidArgumentException('Question not found.');
        }

        $this->deleteStagingPath($items[$itemIndex]['diagram_staging_path'] ?? null);

        unset($items[$itemIndex]['diagram_staging_path'], $items[$itemIndex]['diagram_preview_url']);
        $items[$itemIndex]['needs_diagram'] = false;

        $chapter->update(['extraction_items' => $items]);

        if ($chapter->status === TextbookChapter::STATUS_PUBLISHED) {
            $question = $this->publishedQuestionForItemIndex($chapter, $itemIndex);
            if ($question) {
                app(QuestionDiagramService::class)->deleteForQuestion($question);
            }
        }

        return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']);
    }

    private function syncPublishedQuestionDiagram(TextbookChapter $chapter, int $itemIndex, string $stagingPath): void
    {
        $question = $this->publishedQuestionForItemIndex($chapter, $itemIndex);
        if (! $question || ! Storage::disk('public')->exists($stagingPath)) {
            return;
        }

        app(QuestionDiagramService::class)->attachFromPath(
            $question,
            Storage::disk('public')->path($stagingPath),
        );
    }

    private function publishedQuestionForItemIndex(TextbookChapter $chapter, int $itemIndex): ?Question
    {
        if ($chapter->status !== TextbookChapter::STATUS_PUBLISHED) {
            return null;
        }

        $position = $itemIndex + 1;
        $setPlan = $chapter->mcq_set_plan ?? [];
        $worksheetIds = $chapter->mcqWorksheetIds();

        if ($setPlan === [] || $worksheetIds === []) {
            return null;
        }

        $worksheets = Worksheet::query()
            ->whereIn('id', $worksheetIds)
            ->orderBy('set_number')
            ->get()
            ->values();

        foreach ($setPlan as $planIndex => $row) {
            $qFrom = (int) ($row['q_from'] ?? 0);
            $qTo = (int) ($row['q_to'] ?? 0);

            if ($position < $qFrom || $position > $qTo) {
                continue;
            }

            $worksheet = $worksheets[$planIndex] ?? null;
            if (! $worksheet) {
                return null;
            }

            $questions = $worksheet->questions()->orderByPivot('sort_order')->get();

            return $questions[$position - $qFrom] ?? null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function deleteStagingPathForItem(array $item): void
    {
        $this->deleteStagingPath($item['diagram_staging_path'] ?? null);
    }

    private function deleteStagingPath(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsWithDiagramPreviewUrls(array $items): array
    {
        return collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item) {
                $path = trim((string) ($item['diagram_staging_path'] ?? ''));
                if ($path !== '' && Storage::disk('public')->exists($path)) {
                    $item['diagram_preview_url'] = Storage::disk('public')->url($path);
                }

                return $item;
            })
            ->values()
            ->all();
    }

    private function persistStagingDiagram(TextbookChapter $chapter, string $sourcePath, ?string $extension = null): string
    {
        $directory = $this->stagingDiagramDirectory($chapter);
        Storage::disk('public')->makeDirectory($directory);

        $extension = strtolower($extension ?: pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png');
        $destination = $directory.'/'.Str::uuid().'.'.$extension;

        $contents = file_get_contents($sourcePath);
        if ($contents === false) {
            throw new InvalidArgumentException('Could not read diagram image from the zip file.');
        }

        Storage::disk('public')->put($destination, $contents);

        return $destination;
    }

    private function stagingDiagramDirectory(TextbookChapter $chapter): string
    {
        $chapter->loadMissing('textbook');

        return sprintf(
            'textbooks/%d/chapters/%s/import-diagrams',
            $chapter->textbook_id,
            $chapter->chapter_number,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function parseToItemsFromPayload(array $payload): array
    {
        $rows = isset($payload['questions']) && is_array($payload['questions'])
            ? $payload['questions']
            : $payload;

        if (! is_array($rows) || $rows === []) {
            throw new InvalidArgumentException('No questions found in JSON.');
        }

        $items = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = $this->normalizeImportedRow($row, $index);
            if (trim((string) ($normalized['question_text'] ?? '')) === '') {
                continue;
            }

            $items[] = $normalized;
        }

        if ($items === []) {
            throw new InvalidArgumentException('Could not parse any questions from JSON.');
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeImportedRow(
        array $row,
        int $index,
        ?string $diagramStagingPath = null,
        ?string $diagramFile = null,
    ): array {
        $options = collect($row['options'] ?? []);
        $correctIndex = isset($row['correct_index']) ? (int) $row['correct_index'] : null;

        $mcqOptions = $options
            ->values()
            ->map(function ($option, $optIndex) use ($correctIndex) {
                if (is_array($option)) {
                    return [
                        'text' => trim((string) ($option['text'] ?? $option['option_text'] ?? '')),
                        'is_correct' => (bool) ($option['is_correct'] ?? false) || $correctIndex === $optIndex,
                    ];
                }

                return [
                    'text' => trim((string) $option),
                    'is_correct' => $correctIndex === $optIndex,
                ];
            })
            ->filter(fn (array $option) => $option['text'] !== '')
            ->values()
            ->all();

        $correct = collect($mcqOptions)->firstWhere('is_correct', true);
        $topic = trim((string) ($row['topic'] ?? $row['topic_name'] ?? ''));
        $label = $topic !== '' ? $topic.' · Q'.($index + 1) : 'Q'.($index + 1);
        $resolvedDiagramFile = $diagramFile ?? trim((string) ($row['diagram_file'] ?? $row['chart_file'] ?? ''));
        $hasDiagram = $diagramStagingPath !== null;

        return [
            'id' => 'mcq-'.($index + 1),
            'kind' => 'textbook_mcq',
            'label' => $label,
            'topic' => $topic,
            'source_page' => 0,
            'question_text' => $this->composeQuestionText($row),
            'correct_answer' => trim((string) ($correct['text'] ?? '')),
            'answer_format' => 'text',
            'explanation' => trim((string) ($row['explanation'] ?? '')),
            'method_hint' => trim((string) ($row['method_hint'] ?? $row['hint'] ?? '')),
            'difficulty' => trim((string) ($row['difficulty'] ?? '')),
            'needs_diagram' => $hasDiagram || filter_var($row['needs_diagram'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'diagram_file' => $resolvedDiagramFile !== '' ? $resolvedDiagramFile : null,
            'diagram_staging_path' => $diagramStagingPath,
            'include_in_mcq' => true,
            'include_in_written' => false,
            'approved' => true,
            'mcq_options' => $mcqOptions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $json): array
    {
        $json = trim($json);

        if (preg_match('/^```(?:json)?\s*(.*?)```\s*$/is', $json, $matches)) {
            $json = trim($matches[1]);
        } else {
            $json = preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/', '', $json) ?? $json) ?? $json;
        }

        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{\s*"questions"\s*:\s*\[[\s\S]*\]\s*(?:,\s*"set_plan"\s*:\s*\[[\s\S]*?\]\s*)?\}/', $json, $matches)) {
            $data = json_decode($matches[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        throw new InvalidArgumentException('Invalid JSON. Paste {"questions": [...], "set_plan": [...]} from Cursor.');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function composeQuestionText(array $row): string
    {
        $parts = [];

        $question = trim((string) ($row['question'] ?? $row['question_text'] ?? ''));
        if ($question !== '') {
            $parts[] = $question;
        }

        foreach (['context', 'passage', 'intro'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        foreach (['chart', 'chart_description', 'figure_description', 'graph'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $parts[] = "Chart:\n".$value;
            }
        }

        foreach (['table', 'data_table', 'table_markdown', 'chart_table'] as $key) {
            if (! array_key_exists($key, $row)) {
                continue;
            }

            $formatted = is_array($row[$key])
                ? $this->formatTableValue($row[$key])
                : trim((string) $row[$key]);

            if ($formatted !== '') {
                $parts[] = "Table:\n".$formatted;
            }
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  array<int|string, mixed>  $table
     */
    private function formatTableValue(array $table): string
    {
        if (isset($table['headers'], $table['rows'])
            && is_array($table['headers'])
            && is_array($table['rows'])) {
            $headers = array_map(strval(...), $table['headers']);
            $lines = [
                implode(' | ', $headers),
                str_repeat('-', min(80, max(12, strlen(implode(' | ', $headers))))),
            ];

            foreach ($table['rows'] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $lines[] = implode(' | ', array_map(strval(...), $row));
            }

            return implode("\n", $lines);
        }

        $rows = array_values($table);
        if ($rows !== [] && is_array($rows[0])) {
            $headers = array_keys($rows[0]);
            $lines = [
                implode(' | ', $headers),
                str_repeat('-', min(80, max(12, strlen(implode(' | ', $headers))))),
            ];

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $lines[] = implode(' | ', array_map(
                    fn (string $header) => (string) ($row[$header] ?? ''),
                    $headers,
                ));
            }

            return implode("\n", $lines);
        }

        $encoded = json_encode($table, JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }
}
