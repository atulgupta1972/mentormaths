<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\User;
use App\Support\DiagramQuestionSupport;
use App\Support\QuestionBankPurpose;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ZipArchive;

class QuestionZipImportService
{
    public const TYPE_MCQ = 'mcq';

    public const TYPE_FILL_IN_BLANK = 'fill_in_blank';

    public function __construct(
        private McqImportService $mcqImport,
        private FillBlankImportService $fillBlankImport,
        private QuestionDiagramService $diagramService,
    ) {}

    /**
     * @param  list<int>|null  $keepIndices  0-based indices to keep; null keeps all
     * @return array{type: string, saved: list<Question>, diagram_count: int, missing_diagram_count: int, sheet_pdf_path: ?string, temp_dir: string}
     */
    public function importPack(
        UploadedFile $zip,
        User $user,
        ?SyllabusTopic $topic,
        ?SyllabusChapter $chapter,
        string $bankPurpose = QuestionBankPurpose::PRACTICE_SET,
        ?array $keepIndices = null,
    ): array {
        $extracted = $this->extract($zip);
        $persistedPdfPath = null;

        try {
            if ($keepIndices !== null) {
                $extracted = $this->filterExtracted($extracted, $keepIndices);
            }

            if ($extracted['rows'] === []) {
                throw new InvalidArgumentException('No questions left to import. Keep at least one question in the preview.');
            }

            $saved = match (true) {
                $extracted['type'] === self::TYPE_FILL_IN_BLANK && $chapter !== null
                    => $this->fillBlankImport->saveChapterRows(
                        $chapter,
                        $extracted['rows'],
                        $user->id,
                        Question::SOURCE_AI,
                        $bankPurpose,
                    ),
                $extracted['type'] === self::TYPE_FILL_IN_BLANK && $topic !== null
                    => $this->fillBlankImport->saveRows(
                        $topic,
                        $extracted['rows'],
                        $user->id,
                        Question::SOURCE_AI,
                        $bankPurpose,
                    ),
                $extracted['type'] === self::TYPE_MCQ && $chapter !== null
                    => $this->mcqImport->saveChapterRows(
                        $chapter,
                        $extracted['rows'],
                        $user->id,
                        Question::SOURCE_AI,
                        $bankPurpose,
                    ),
                $extracted['type'] === self::TYPE_MCQ && $topic !== null
                    => $this->mcqImport->saveRows(
                        $topic,
                        $extracted['rows'],
                        $user->id,
                        Question::SOURCE_AI,
                        $bankPurpose,
                    ),
                default => throw new InvalidArgumentException('Select a topic or chapter before importing.'),
            };

            $diagramCount = 0;
            $missingDiagramCount = 0;
            $geometryChapter = $chapter ?? $topic?->chapter;

            foreach ($saved as $index => $question) {
                $path = $extracted['diagram_paths'][$index] ?? null;
                $rawItem = $extracted['items'][$index] ?? [];

                if ($path && is_file($path)) {
                    $this->diagramService->attachFromPath($question, $path);
                    $diagramCount++;

                    continue;
                }

                if (DiagramQuestionSupport::shouldExpectDiagram($rawItem, $geometryChapter)) {
                    $missingDiagramCount++;
                }
            }

            $persistedPdfPath = $this->persistSheetPdf($extracted['sheet_pdf_path'] ?? null);

            return [
                'type' => $extracted['type'],
                'saved' => $saved,
                'diagram_count' => $diagramCount,
                'missing_diagram_count' => $missingDiagramCount,
                'sheet_pdf_path' => $persistedPdfPath,
                'temp_dir' => $extracted['temp_dir'],
            ];
        } catch (\Throwable $e) {
            if ($persistedPdfPath && is_file($persistedPdfPath)) {
                @unlink($persistedPdfPath);
            }

            throw $e;
        } finally {
            $this->cleanup($extracted['temp_dir']);
        }
    }

    /**
     * @param  array{
     *     type: string,
     *     rows: list<array<string, mixed>>,
     *     diagram_paths: array<int, string|null>,
     *     items: list<array<string, mixed>>,
     *     temp_dir: string
     * }  $extracted
     * @param  list<int>  $keepIndices
     * @return array{
     *     type: string,
     *     rows: list<array<string, mixed>>,
     *     diagram_paths: array<int, string|null>,
     *     items: list<array<string, mixed>>,
     *     temp_dir: string
     * }
     */
    private function filterExtracted(array $extracted, array $keepIndices): array
    {
        $total = count($extracted['rows']);
        $normalized = [];

        foreach ($keepIndices as $index) {
            $index = (int) $index;
            if ($index < 0 || $index >= $total) {
                throw new InvalidArgumentException("Keep index {$index} is out of range for this zip pack.");
            }
            $normalized[$index] = $index;
        }

        $ordered = array_values($normalized);
        sort($ordered);

        $rows = [];
        $items = [];
        $diagramPaths = [];

        foreach ($ordered as $index) {
            $rows[] = $extracted['rows'][$index];
            $items[] = $extracted['items'][$index] ?? [];
            $diagramPaths[] = $extracted['diagram_paths'][$index] ?? null;
        }

        $extracted['rows'] = $rows;
        $extracted['items'] = $items;
        $extracted['diagram_paths'] = $diagramPaths;

        return $extracted;
    }

    /**
     * Parse a zip pack for UI preview without saving questions.
     *
     * @return array{
     *     type: string,
     *     type_label: string,
     *     question_count: int,
     *     diagram_count: int,
     *     missing_diagram_count: int,
     *     questions: list<array<string, mixed>>
     * }
     */
    public function previewPack(UploadedFile $zip): array
    {
        $extracted = $this->extract($zip);

        try {
            $questions = [];
            $diagramCount = 0;
            $missingDiagramCount = 0;

            foreach ($extracted['rows'] as $index => $row) {
                $rawItem = $extracted['items'][$index] ?? [];
                $path = $extracted['diagram_paths'][$index] ?? null;
                $needsDiagram = DiagramQuestionSupport::needsDiagram($rawItem)
                    || ! empty($row['needs_diagram']);
                $hasDiagram = is_string($path) && is_file($path);
                $diagramPreviewUrl = null;
                $diagramFile = null;

                if ($hasDiagram) {
                    $diagramCount++;
                    $diagramFile = basename($path);
                    $diagramPreviewUrl = $this->diagramDataUrl($path);
                } elseif ($needsDiagram) {
                    $missingDiagramCount++;
                }

                if ($extracted['type'] === self::TYPE_FILL_IN_BLANK) {
                    $questions[] = [
                        'index' => $index + 1,
                        'question_text' => (string) ($row['question_text'] ?? ''),
                        'topic_name' => (string) ($row['topic_name'] ?? ''),
                        'answer_format' => (string) ($row['answer_format'] ?? ''),
                        'correct_answer' => (string) ($row['correct_answer'] ?? ''),
                        'difficulty' => (string) ($row['difficulty'] ?? ''),
                        'method_hint' => (string) ($row['method_hint'] ?? ''),
                        'explanation' => (string) ($row['explanation'] ?? ''),
                        'needs_diagram' => $needsDiagram,
                        'has_diagram' => $hasDiagram,
                        'diagram_file' => $diagramFile,
                        'diagram_preview_url' => $diagramPreviewUrl,
                    ];

                    continue;
                }

                $questions[] = [
                    'index' => $index + 1,
                    'question_text' => (string) ($row['question_text'] ?? ''),
                    'topic_name' => (string) ($row['topic_name'] ?? ''),
                    'options' => array_values($row['options'] ?? []),
                    'correct_index' => $row['correct_index'] ?? null,
                    'difficulty' => (string) ($row['difficulty'] ?? ''),
                    'method_hint' => (string) ($row['method_hint'] ?? ''),
                    'explanation' => (string) ($row['explanation'] ?? ''),
                    'needs_diagram' => $needsDiagram,
                    'has_diagram' => $hasDiagram,
                    'diagram_file' => $diagramFile,
                    'diagram_preview_url' => $diagramPreviewUrl,
                ];
            }

        return [
            'type' => $extracted['type'],
            'type_label' => $extracted['type'] === self::TYPE_FILL_IN_BLANK
                ? 'fill-in-the-blank'
                : 'MCQ',
            'question_count' => count($questions),
            'diagram_count' => $diagramCount,
            'missing_diagram_count' => $missingDiagramCount,
            'has_sheet_pdf' => is_string($extracted['sheet_pdf_path'] ?? null)
                && is_file($extracted['sheet_pdf_path']),
            'questions' => $questions,
        ];
        } finally {
            $this->cleanup($extracted['temp_dir']);
        }
    }

    /**
     * @return array{
     *     type: string,
     *     rows: list<array<string, mixed>>,
     *     diagram_paths: array<int, string|null>,
     *     items: list<array<string, mixed>>,
     *     sheet_pdf_path: ?string,
     *     temp_dir: string
     * }
     */
    public function extract(UploadedFile $zip): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new InvalidArgumentException('Zip support is not enabled on this server (ZipArchive missing).');
        }

        $tempDir = storage_path('app/temp/question-import-'.Str::uuid());
        File::ensureDirectoryExists($tempDir);

        $archive = new ZipArchive;
        $opened = $archive->open($zip->getRealPath());

        if ($opened !== true) {
            throw new InvalidArgumentException('Could not open the zip file.');
        }

        if (! $archive->extractTo($tempDir)) {
            $archive->close();
            $this->cleanup($tempDir);

            throw new InvalidArgumentException('Could not extract the zip file.');
        }

        $archive->close();

        $jsonPath = $this->findJsonFile($tempDir);
        if ($jsonPath === null) {
            $this->cleanup($tempDir);

            throw new InvalidArgumentException('Zip must contain questions.json (or any .json file with a "questions" array).');
        }

        $json = file_get_contents($jsonPath);
        if ($json === false) {
            $this->cleanup($tempDir);

            throw new InvalidArgumentException('Could not read JSON from the zip file.');
        }

        $data = json_decode($this->stripMarkdownFences($json), true);
        if (! is_array($data)) {
            $this->cleanup($tempDir);

            throw new InvalidArgumentException('Invalid JSON inside the zip file.');
        }

        $items = $data['questions'] ?? $data;
        if (! is_array($items) || $items === []) {
            $this->cleanup($tempDir);

            throw new InvalidArgumentException('JSON must include a non-empty "questions" array.');
        }

        $type = $this->detectType($items);
        $imageMap = $this->collectImages($tempDir);

        $rows = [];
        $diagramPaths = [];
        $rawItems = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $rawItems[] = $item;

            if ($type === self::TYPE_FILL_IN_BLANK) {
                $rows[] = $this->fillBlankImport->parseJson(json_encode(['questions' => [$item]]))[0];
            } else {
                $rows[] = $this->mcqImport->parseJson(json_encode(['questions' => [$item]]))[0];
            }

            $diagramPaths[$index] = $this->resolveDiagramPath($item, $index, $imageMap);
        }

        if ($rows === []) {
            $this->cleanup($tempDir);

            throw new InvalidArgumentException('No questions could be parsed from the zip JSON.');
        }

        return [
            'type' => $type,
            'rows' => $rows,
            'diagram_paths' => $diagramPaths,
            'items' => $rawItems,
            'sheet_pdf_path' => $this->findSheetPdf($tempDir),
            'temp_dir' => $tempDir,
        ];
    }

    private function findSheetPdf(string $directory): ?string
    {
        $preferredNames = [
            'sheet.pdf',
            'worksheet.pdf',
            'questions.pdf',
            'written.pdf',
            'paper.pdf',
        ];

        $pdfFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'pdf') {
                continue;
            }

            $pdfFiles[] = $file->getPathname();
        }

        if ($pdfFiles === []) {
            return null;
        }

        foreach ($preferredNames as $name) {
            foreach ($pdfFiles as $path) {
                if (strtolower(basename($path)) === $name) {
                    return $path;
                }
            }
        }

        usort($pdfFiles, fn (string $a, string $b) => strlen(basename($a)) <=> strlen(basename($b)));

        return $pdfFiles[0];
    }

    private function persistSheetPdf(?string $sourcePath): ?string
    {
        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            return null;
        }

        $destDir = storage_path('app/temp/written-pdf-import');
        File::ensureDirectoryExists($destDir);
        $destPath = $destDir.DIRECTORY_SEPARATOR.Str::uuid().'.pdf';

        if (! @copy($sourcePath, $destPath)) {
            return null;
        }

        return $destPath;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function detectType(array $items): string
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (! empty($item['options']) || array_key_exists('correct_index', $item)) {
                return self::TYPE_MCQ;
            }

            if (! empty($item['answer_format']) || ! empty($item['correct_answer']) || ! empty($item['answer'])) {
                return self::TYPE_FILL_IN_BLANK;
            }
        }

        return self::TYPE_MCQ;
    }

    private function findJsonFile(string $directory): ?string
    {
        $preferred = $directory.DIRECTORY_SEPARATOR.'questions.json';
        if (is_file($preferred)) {
            return $preferred;
        }

        $jsonFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }

            $jsonFiles[] = $file->getPathname();
        }

        if ($jsonFiles === []) {
            return null;
        }

        usort($jsonFiles, function (string $a, string $b) {
            $score = fn (string $path) => str_contains(strtolower(basename($path)), 'question') ? 0 : 1;

            return [$score($a), strlen($a)] <=> [$score($b), strlen($b)];
        });

        return $jsonFiles[0];
    }

    /**
     * @return array<string, string> basename (lower) => absolute path
     */
    private function collectImages(string $directory): array
    {
        $images = [];
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (! in_array($ext, $allowed, true)) {
                continue;
            }

            $basename = strtolower($file->getBasename());
            $images[$basename] = $file->getPathname();
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, string>  $imageMap
     */
    private function resolveDiagramPath(array $item, int $index, array $imageMap): ?string
    {
        $candidates = [];

        $diagramFile = trim((string) ($item['diagram_file'] ?? $item['chart_file'] ?? $item['diagram'] ?? ''));
        if ($diagramFile !== '') {
            $candidates[] = basename($diagramFile);
        }

        $candidates[] = 'q'.($index + 1).'.jpg';
        $candidates[] = 'q'.($index + 1).'.jpeg';
        $candidates[] = 'q'.($index + 1).'.png';
        $candidates[] = 'q'.($index + 1).'.webp';
        $candidates[] = ($index + 1).'.jpg';

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($imageMap[$key])) {
                return $imageMap[$key];
            }
        }

        return null;
    }

    private function stripMarkdownFences(string $json): string
    {
        $json = trim($json);
        if (preg_match('/^```(?:json)?\s*(.*?)```\s*$/is', $json, $matches)) {
            return trim($matches[1]);
        }

        return preg_replace('/^```(?:json)?\s*/i', '', preg_replace('/\s*```$/', '', $json) ?? $json) ?? $json;
    }

    private function diagramDataUrl(string $path): ?string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        // Keep preview payloads modest for browser JSON responses.
        if (strlen($bytes) > 2_500_000) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function cleanup(string $directory): void
    {
        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }
    }
}
