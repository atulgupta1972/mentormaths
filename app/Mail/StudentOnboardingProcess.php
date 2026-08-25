<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentOnboardingProcess extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Student $student,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->payload['student_name'] ?? $this->student->name;

        return new Envelope(
            subject: "Mentor Maths — how to start, {$name} (complete process)",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-onboarding-process',
            with: [
                'payload' => $this->payload,
            ],
        );
    }
}
