<?php

namespace App\Services;

use App\Models\Student;
use App\Support\AssignmentMailer;
use Barryvdh\DomPDF\Facade\Pdf;

class StudentProgressPdfService
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function render(array $summary): string
    {
        return Pdf::loadView('reports.student-progress-summary-pdf', [
            'summary' => $summary,
        ])->output();
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
}
