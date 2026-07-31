<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WrittenWorkCheckFailed extends Mailable
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
        return new Envelope(
            subject: "Mentor Maths — {$this->summary['set_code']} upload received · teacher will mark",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.written-work-check-failed',
            with: [
                'studentName' => $this->summary['student_name'],
                'summary' => $this->summary,
            ],
        );
    }
}
