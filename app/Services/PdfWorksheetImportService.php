<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PdfWorksheetImportService
{
    public function __construct(
        private PdfTextExtractionService $textService,
        private PdfPageImageService $pageService,
        private McqImportService $importService,
    ) {}

    /**
     * @return array{
     *     token: string,
     *     rows: list<array<string, mixed>>,
     *     page_urls: list<string>,
     *     pdf_url: string,
     *     page_count: int,
     *     page_assignments: list<int|null>,
     *     warning: string|null,
     *     parsed_from_text: bool
     * }
     */
    public function process(UploadedFile $file): array
    {
        $token = Str::uuid()->toString();
        $pdfPath = $this->pageService->storeUploadedPdf($file, $token);
        $pdfUrl = $this->pageService->publicUrl($pdfPath);

        $pagePaths = [];
        $warning = null;

        if ($this->pageService->isAvailable()) {
            try {
                $pagePaths = $this->pageService->renderPages($pdfPath, "temp/pdf-imports/{$token}");
            } catch (InvalidArgumentException $e) {
                $warning = $e->getMessage();
            }
        } else {
            $warning = 'Ghostscript is not installed on this server. The full PDF will be shown during practice; individual page images could not be generated automatically.';
        }

        $pageUrls = array_map(fn (string $path) => $this->pageService->publicUrl($path), $pagePaths);
        $pageCount = count($pagePaths);

        $rows = [];
        $parsedFromText = false;

        try {
            $text = $this->textService->extract($file);
            try {
                $rows = $this->importService->parseFromWorksheetText($text);
                $parsedFromText = $rows !== [];
            } catch (InvalidArgumentException) {
                // Fall through to page-based placeholders.
            }
        } catch (InvalidArgumentException) {
            // Image-only or scanned PDF — use page placeholders below.
        }

        if ($rows === [] && $pageCount > 0) {
            $rows = $this->placeholderRowsForPages($pageCount);
        }

        if ($rows === [] && $pageCount === 0) {
            $rows = [$this->placeholderRow(1)];
            $warning = trim(($warning ? $warning.' ' : '')
                .'Could not extract text or page images. One placeholder question was created — open the PDF below and fill in options in Step 3.');
        }

        $pageAssignments = $this->assignPages(count($rows), $pageCount);

        Cache::put("pdf_import:{$token}", [
            'token' => $token,
            'pdf_path' => $pdfPath,
            'page_paths' => $pagePaths,
            'page_assignments' => $pageAssignments,
        ], now()->addHours(3));

        return [
            'token' => $token,
            'rows' => $rows,
            'page_urls' => $pageUrls,
            'pdf_url' => $pdfUrl,
            'page_count' => $pageCount,
            'page_assignments' => $pageAssignments,
            'warning' => $warning,
            'parsed_from_text' => $parsedFromText,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pullImport(string $token): ?array
    {
        return Cache::pull("pdf_import:{$token}");
    }

    /**
     * @return list<int|null>
     */
    private function assignPages(int $questionCount, int $pageCount): array
    {
        if ($pageCount === 0) {
            return array_fill(0, $questionCount, null);
        }

        if ($pageCount === 1) {
            return array_fill(0, $questionCount, 0);
        }

        if ($pageCount === $questionCount) {
            return range(0, $questionCount - 1);
        }

        if ($questionCount > $pageCount) {
            return array_fill(0, $questionCount, 0);
        }

        $assignment = [];
        for ($i = 0; $i < $questionCount; $i++) {
            $assignment[] = min($i, $pageCount - 1);
        }

        return $assignment;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function placeholderRowsForPages(int $pageCount): array
    {
        $rows = [];
        for ($i = 1; $i <= $pageCount; $i++) {
            $rows[] = $this->placeholderRow($i);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function placeholderRow(int $number): array
    {
        return [
            'question_text' => "Question {$number} — see diagram",
            'explanation' => '',
            'difficulty' => 'Medium',
            'options' => [
                ['option_text' => 'Option A', 'is_correct' => true, 'sort_order' => 1],
                ['option_text' => 'Option B', 'is_correct' => false, 'sort_order' => 2],
                ['option_text' => 'Option C', 'is_correct' => false, 'sort_order' => 3],
                ['option_text' => 'Option D', 'is_correct' => false, 'sort_order' => 4],
            ],
        ];
    }
}
