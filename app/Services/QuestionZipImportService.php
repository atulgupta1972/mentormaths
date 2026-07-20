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
     * @return array{type: string, saved: list<Question>, diagram_count: int, missing_diagram_count: int, temp_dir: string}
     */
    public function importPack(
        UploadedFile $zip,
        User $user,
        ?SyllabusTopic $topic,
        ?SyllabusChapter $chapter,
        string $bankPurpose = QuestionBankPurpose::PRACTICE_SET,
    ): array {
        $extracted = $this->extract($zip);

        try {
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

            return [
                'type' => $extracted['type'],
                'saved' => $saved,
                'diagram_count' => $diagramCount,
                'missing_diagram_count' => $missingDiagramCount,
                'temp_dir' => $extracted['temp_dir'],
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
            'temp_dir' => $tempDir,
        ];
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

        $diagramFile = trim((string) ($item['diagram_file'] ?? $item['diagram'] ?? ''));
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

    private function cleanup(string $directory): void
    {
        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }
    }
}
