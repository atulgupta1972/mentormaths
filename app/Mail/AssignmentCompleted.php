<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssignmentCompleted extends Mailable
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
            subject: "Mentor Maths — {$summary['student_name']} completed {$summary['set_code']} ({$summary['score_label']} · {$summary['time_label']})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.assignment-completed',
            with: [
                'studentName' => $this->summary['student_name'],
                'summary' => $this->summary,
                'dashboardUrl' => route('dashboard'),
            ],
        );
    }
}
