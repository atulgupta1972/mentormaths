<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;

class PdfTextExtractionService
{
    /**
     * Extract PDF text. Keeps line breaks and tabs by default so answer-key tables stay readable.
     * Pass $flattenWhitespace = true for callers that prefer a single spaced string.
     */
    public function extract(UploadedFile $file, bool $flattenWhitespace = false): string
    {
        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($file->getRealPath());
            $raw = (string) $pdf->getText();
            $text = $flattenWhitespace
                ? trim((string) preg_replace('/\s+/u', ' ', $raw))
                : $this->normalizeLayout($raw);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Could not read the PDF file. Try a different file or export as text-based PDF.');
        }

        if (mb_strlen(preg_replace('/\s+/u', ' ', $text) ?? $text) < 20) {
            throw new InvalidArgumentException(
                'Very little text was found in this PDF. Scanned image PDFs need OCR first — use a text-based PDF or paste content manually in Custom prompt → Focus field.',
            );
        }

        return $text;
    }

    private function normalizeLayout(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Keep newlines and tabs (answer-key tables); collapse other runs of spaces.
        $text = preg_replace('/[^\S\n\t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
