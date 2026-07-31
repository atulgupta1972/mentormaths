<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WrittenUploadOptimizer
{
    private const DISK = 'public';

    private const MAX_EDGE = 2000;

    private const JPEG_QUALITY = 85;

    /**
     * Store an upload, compressing photos so large camera images grade faster.
     *
     * @return string Storage path on the public disk
     */
    public function storeOptimized(UploadedFile $file, string $directory): string
    {
        Storage::disk(self::DISK)->makeDirectory($directory);

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        if ($extension === 'pdf' || ! $this->canOptimizeImages()) {
            $filename = Str::uuid().'.'.$extension;

            return $file->storeAs($directory, $filename, self::DISK);
        }

        $filename = Str::uuid().'.jpg';
        $path = $directory.'/'.$filename;
        $absolute = Storage::disk(self::DISK)->path($path);

        if (! $this->writeOptimizedImage($file->getRealPath(), $absolute)) {
            $filename = Str::uuid().'.'.$extension;

            return $file->storeAs($directory, $filename, self::DISK);
        }

        return $path;
    }

    /**
     * Build a smaller copy for AI grading when the stored file is still large.
     *
     * @return string Storage path on the public disk
     */
    public function gradingCopyPath(string $sourcePath): string
    {
        $absolute = Storage::disk(self::DISK)->path($sourcePath);

        if (! is_file($absolute)) {
            return $sourcePath;
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        if ($extension === 'pdf' || ! $this->canOptimizeImages()) {
            return $sourcePath;
        }

        if (filesize($absolute) <= 900_000 && $this->imageEdge($absolute) <= self::MAX_EDGE) {
            return $sourcePath;
        }

        $directory = 'temp/written-grading/optimized/'.md5($sourcePath);
        Storage::disk(self::DISK)->makeDirectory($directory);

        $targetPath = $directory.'/grading.jpg';
        $targetAbsolute = Storage::disk(self::DISK)->path($targetPath);

        if ($this->writeOptimizedImage($absolute, $targetAbsolute, 1600, 80)) {
            return $targetPath;
        }

        return $sourcePath;
    }

    private function canOptimizeImages(): bool
    {
        return function_exists('imagecreatefromjpeg')
            && function_exists('imagejpeg')
            && function_exists('imagescale');
    }

    private function writeOptimizedImage(
        string $source,
        string $destination,
        int $maxEdge = self::MAX_EDGE,
        int $quality = self::JPEG_QUALITY,
    ): bool {
        $image = $this->loadImage($source);

        if (! $image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $largest = max($width, $height);

        if ($largest > $maxEdge) {
            $scale = $maxEdge / $largest;
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $scaled = imagescale($image, $targetWidth, $targetHeight, IMG_BILINEAR_FIXED);

            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        $saved = imagejpeg($image, $destination, $quality);
        imagedestroy($image);

        return $saved;
    }

    private function loadImage(string $path): ?\GdImage
    {
        $mime = mime_content_type($path) ?: '';

        return match (true) {
            str_contains($mime, 'png') => @imagecreatefrompng($path) ?: null,
            str_contains($mime, 'webp') && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($path) ?: null,
            default => @imagecreatefromjpeg($path) ?: @imagecreatefrompng($path) ?: null,
        };
    }

    private function imageEdge(string $path): int
    {
        $size = @getimagesize($path);

        if (! is_array($size)) {
            return 0;
        }

        return max((int) ($size[0] ?? 0), (int) ($size[1] ?? 0));
    }
}
