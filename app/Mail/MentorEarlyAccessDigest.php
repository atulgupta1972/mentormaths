<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MentorEarlyAccessDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public User $mentor,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $stats = $this->payload['stats'] ?? [];
        $total = (int) ($stats['total'] ?? 0);
        $date = $this->payload['as_of_label'] ?? now()->format('d M Y');

        $subject = $total > 0
            ? "Mentor Maths early access — {$total} student(s) under you ({$date})"
            : "Mentor Maths early access — ask students to enrol ({$date})";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mentor-early-access-digest',
            with: [
                'payload' => $this->payload,
            ],
        );
    }
}
