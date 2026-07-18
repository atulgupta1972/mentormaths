<?php

namespace App\Services;

use App\Models\Student;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class StudentProgressPdfService
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function render(array $summary): string
    {
        $chartPaths = $this->materializeChartFiles($summary['charts'] ?? []);

        try {
            return Pdf::loadView('reports.student-progress-summary-pdf', [
                'summary' => $summary,
                'chartPaths' => $chartPaths,
            ])->output();
        } finally {
            $this->cleanupChartFiles($chartPaths);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function filename(Student $student, array $summary): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($student->name)) ?: 'student';
        $date = $summary['as_of_date'] ?? now()->toDateString();

        return "progress-{$slug}-{$date}.pdf";
    }

    /**
     * DomPDF cannot embed data-URI images reliably, so write PNG bytes to disk first.
     *
     * @param  array<string, string>  $charts
     * @return array<string, string>
     */
    private function materializeChartFiles(array $charts): array
    {
        $paths = [];
        $files = [
            'chapter_bar_chart' => 'chapter-bar.png',
            'date_line_chart' => 'date-line.png',
        ];

        foreach ($files as $key => $filename) {
            $bytes = $this->chartBytes($charts[$key] ?? '');

            if ($bytes === '') {
                continue;
            }

            $directory = storage_path('app/temp/pdf-charts');
            File::ensureDirectoryExists($directory);

            $path = $directory.'/'.Str::uuid().'-'.$filename;
            file_put_contents($path, $bytes);
            $paths[$key] = $path;
        }

        return $paths;
    }

    /**
     * @param  array<string, string>  $chartPaths
     */
    private function cleanupChartFiles(array $chartPaths): void
    {
        foreach ($chartPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function chartBytes(string $chart): string
    {
        if ($chart === '') {
            return '';
        }

        if (str_starts_with($chart, 'data:image/png;base64,')) {
            $decoded = base64_decode(substr($chart, 22), true);

            return $decoded !== false ? $decoded : '';
        }

        return $chart;
    }
}
