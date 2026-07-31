<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TextbookChapterExtracted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Mentor Maths — Ch {$this->summary['chapter_number']} extracted · {$this->summary['items_count']} questions ready",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.textbook-chapter-extracted',
            with: [
                'summary' => $this->summary,
            ],
        );
    }
}
