<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DoubtsCleared extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<array<string, mixed>>  $items */
    public function __construct(
        public Student $student,
        public array $items,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Topics — doubts cleared',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.doubts-cleared',
            with: [
                'studentName' => $this->student->name,
                'items' => $this->items,
                'dashboardUrl' => route('dashboard'),
            ],
        );
    }
}
