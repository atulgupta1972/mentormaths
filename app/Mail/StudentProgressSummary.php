<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentProgressSummary extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public Student $student,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        $summary = $this->summary;

        return new Envelope(
            subject: "Mentor Maths — Progress summary for {$summary['student_name']} (as on {$summary['as_of_label']})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-progress-summary',
            with: [
                'studentName' => $this->summary['student_name'],
                'summary' => $this->summary,
            ],
        );
    }
}
