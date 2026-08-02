<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class PdfPageImageService
{
    private const DISK = 'public';

    public function isAvailable(): bool
    {
        return $this->ghostscriptBinary() !== null || $this->pdftoppmBinary() !== null;
    }

    /**
     * @return list<string> Storage paths on the public disk
     */
    public function renderPages(string $pdfPath, string $outputDirectory): array
    {
        Storage::disk(self::DISK)->makeDirectory($outputDirectory);

        $pdfFullPath = Storage::disk(self::DISK)->path($pdfPath);
        $outPattern = Storage::disk(self::DISK)->path($outputDirectory.'/page');

        if ($this->ghostscriptBinary()) {
            return $this->renderWithGhostscript($pdfFullPath, $outPattern.'-%d.png', $outputDirectory);
        }

        if ($this->pdftoppmBinary()) {
            return $this->renderWithPdftoppm($pdfFullPath, $outPattern, $outputDirectory);
        }

        throw new InvalidArgumentException(
            'PDF pages cannot be converted on this server. Install Ghostscript (gs) or poppler-utils (pdftoppm), or upload JPG/PNG photos instead.',
        );
    }

    /**
     * @return list<string>
     */
    private function renderWithGhostscript(string $pdfFullPath, string $outPattern, string $outputDirectory): array
    {
        $gs = $this->ghostscriptBinary();
        $command = sprintf(
            '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r150 -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -sOutputFile=%s %s 2>&1',
            escapeshellarg($gs),
            escapeshellarg($outPattern),
            escapeshellarg($pdfFullPath),
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new InvalidArgumentException(
                'Could not render PDF pages. '.trim(implode(' ', $output)),
            );
        }

        return $this->collectRenderedPages($outputDirectory);
    }

    /**
     * @return list<string>
     */
    private function renderWithPdftoppm(string $pdfFullPath, string $outPrefix, string $outputDirectory): array
    {
        $pdftoppm = $this->pdftoppmBinary();
        $command = sprintf(
            '%s -png -r 150 %s %s 2>&1',
            escapeshellarg($pdftoppm),
            escapeshellarg($pdfFullPath),
            escapeshellarg($outPrefix),
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            throw new InvalidArgumentException(
                'Could not render PDF pages with pdftoppm. '.trim(implode(' ', $output)),
            );
        }

        return $this->collectRenderedPages($outputDirectory);
    }

    /**
     * @return list<string>
     */
    private function collectRenderedPages(string $outputDirectory): array
    {
        $pages = collect(Storage::disk(self::DISK)->files($outputDirectory))
            ->filter(fn (string $file) => (bool) preg_match('/page-\d+\.png$/', $file))
            ->sort(SORT_NATURAL)
            ->values()
            ->all();

        if ($pages === []) {
            throw new InvalidArgumentException('No pages were generated from the PDF.');
        }

        return $pages;
    }

    public function storeUploadedPdf(UploadedFile $file, string $token): string
    {
        $directory = "temp/pdf-imports/{$token}";
        Storage::disk(self::DISK)->makeDirectory($directory);

        return $file->storeAs($directory, 'source.pdf', self::DISK);
    }

    public function publicUrl(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }

    public function copyToPermanent(string $sourcePath, string $destinationPath): string
    {
        Storage::disk(self::DISK)->makeDirectory(dirname($destinationPath));
        Storage::disk(self::DISK)->copy($sourcePath, $destinationPath);

        return $destinationPath;
    }

    public function deleteImportDirectory(string $token): void
    {
        Storage::disk(self::DISK)->deleteDirectory("temp/pdf-imports/{$token}");
    }

    private function ghostscriptBinary(): ?string
    {
        return $this->resolveBinary(
            PHP_OS_FAMILY === 'Windows'
                ? ['gswin64c', 'gswin32c', 'gs']
                : ['gs', 'gswin64c'],
        );
    }

    private function pdftoppmBinary(): ?string
    {
        return $this->resolveBinary(['pdftoppm']);
    }

    /**
     * @param  list<string>  $candidates
     */
    private function resolveBinary(array $candidates): ?string
    {
        foreach ($candidates as $binary) {
            if (PHP_OS_FAMILY === 'Windows') {
                exec('where '.escapeshellarg($binary).' 2>nul', $output, $exitCode);
                if ($exitCode === 0 && ! empty($output[0])) {
                    return trim($output[0]);
                }
            } else {
                exec('command -v '.escapeshellarg($binary).' 2>/dev/null', $output, $exitCode);
                if ($exitCode === 0 && ! empty($output[0])) {
                    return trim($output[0]);
                }
            }

            exec(escapeshellarg($binary).' --version 2>&1', $versionOutput, $versionCode);
            if ($versionCode === 0) {
                return $binary;
            }
        }

        return null;
    }
}
