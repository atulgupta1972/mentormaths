<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;

class PdfTextExtractionService
{
    public function extract(UploadedFile $file): string
    {
        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($file->getRealPath());
            $text = trim((string) preg_replace('/\s+/u', ' ', $pdf->getText()));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Could not read the PDF file. Try a different file or export as text-based PDF.');
        }

        if (mb_strlen($text) < 20) {
            throw new InvalidArgumentException(
                'Very little text was found in this PDF. Scanned image PDFs need OCR first — use a text-based PDF or paste content manually in Custom prompt → Focus field.',
            );
        }

        return $text;
    }
}
