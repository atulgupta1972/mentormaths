<?php

namespace App\Services;

use App\Models\Worksheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WrittenSheetPdfImportService
{
    public function __construct(
        private PdfPageImageService $pageService,
        private PdfTextExtractionService $textService,
        private WrittenSheetAnswerKeyParser $answerKeyParser,
    ) {}

    /**
     * @return array{token: string, pdf_url: string, page_count: int|null, estimated_question_count: int|null, warning: string|null}
     */
    public function stage(UploadedFile $file): array
    {
        $token = Str::uuid()->toString();
        $pdfPath = $this->pageService->storeUploadedPdf($file, "written-sheet-pdf/{$token}");

        $pageCount = null;
        $estimatedQuestionCount = null;
        $warning = null;

        try {
            $worksheetText = $this->textService->extract($file);
            $estimatedQuestionCount = $this->answerKeyParser->estimateQuestionCountFromWorksheet($worksheetText);
        } catch (InvalidArgumentException) {
            // Worksheet may be image-only; answers can still be typed or uploaded separately.
        }

        if ($this->pageService->isAvailable()) {
            try {
                $pages = $this->pageService->renderPages(
                    $pdfPath,
                    "temp/written-sheet-pdf/{$token}/pages",
                );
                $pageCount = count($pages);
            } catch (InvalidArgumentException $e) {
                $warning = $e->getMessage();
            }
        } else {
            $warning = 'Ghostscript is not installed — page count could not be detected. Upload an answer sheet PDF or add answer rows manually.';
        }

        Cache::put("written_sheet_pdf:{$token}", [
            'token' => $token,
            'pdf_path' => $pdfPath,
            'original_name' => $file->getClientOriginalName(),
            'estimated_question_count' => $estimatedQuestionCount,
        ], now()->addHours(3));

        return [
            'token' => $token,
            'pdf_url' => $this->pageService->publicUrl($pdfPath),
            'page_count' => $pageCount,
            'estimated_question_count' => $estimatedQuestionCount,
            'warning' => $warning,
        ];
    }

    /**
     * @return array{
     *     answer_key: list<array<string, mixed>>,
     *     parsed_count: int,
     *     warnings: list<string>,
     *     extracted_preview: string,
     * }
     */
    public function parseAnswerSheet(UploadedFile $file, ?string $worksheetToken = null): array
    {
        $text = $this->textService->extract($file);

        $expectedCount = null;

        if ($worksheetToken !== null) {
            $cached = Cache::get("written_sheet_pdf:{$worksheetToken}");

            if (is_array($cached) && ! empty($cached['estimated_question_count'])) {
                $expectedCount = (int) $cached['estimated_question_count'];
            }
        }

        $result = $this->answerKeyParser->parseWithExpectedCount($text, $expectedCount);

        return [
            'answer_key' => $result['rows'],
            'parsed_count' => $result['parsed_count'],
            'warnings' => $result['warnings'],
            'extracted_preview' => mb_substr($text, 0, 800),
        ];
    }

    /**
     * @return array{pdf_path: string, original_name: string|null, estimated_question_count: int|null}|null
     */
    public function peek(string $token): ?array
    {
        $payload = Cache::get("written_sheet_pdf:{$token}");

        if (! is_array($payload) || empty($payload['pdf_path'])) {
            return null;
        }

        return [
            'pdf_path' => (string) $payload['pdf_path'],
            'original_name' => isset($payload['original_name']) ? (string) $payload['original_name'] : null,
            'estimated_question_count' => isset($payload['estimated_question_count'])
                ? (int) $payload['estimated_question_count']
                : null,
        ];
    }

    /**
     * @return array{pdf_path: string, original_name: string|null}|null
     */
    public function pull(string $token): ?array
    {
        $payload = Cache::pull("written_sheet_pdf:{$token}");

        if (! is_array($payload) || empty($payload['pdf_path'])) {
            return null;
        }

        return [
            'pdf_path' => (string) $payload['pdf_path'],
            'original_name' => isset($payload['original_name']) ? (string) $payload['original_name'] : null,
        ];
    }

    public function attachToWorksheet(Worksheet $worksheet, string $sourcePath): string
    {
        $filename = Str::slug($worksheet->set_code ?: 'written-sheet').'.pdf';
        $destination = "written-sheets/{$worksheet->id}/{$filename}";

        $this->pageService->copyToPermanent($sourcePath, $destination);

        return $destination;
    }

    public function cleanupTokenDirectory(string $token): void
    {
        Storage::disk('public')->deleteDirectory("temp/pdf-imports/written-sheet-pdf/{$token}");
        Storage::disk('public')->deleteDirectory("temp/written-sheet-pdf/{$token}");
    }
}
