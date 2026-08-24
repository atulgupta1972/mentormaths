<?php

namespace App\Mail;

use App\Models\AccessCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrialSignupAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AccessCode $accessCode,
        public string $summary,
    ) {}

    public function envelope(): Envelope
    {
        $type = $this->accessCode->type === AccessCode::TYPE_MENTOR ? 'Mentor' : 'Student';

        return new Envelope(
            subject: "Self-serve {$type} signup — {$this->accessCode->code}",
        );
    }

    public function content(): Content
    {
        $this->accessCode->loadMissing(['user', 'student', 'coachingClass']);

        return new Content(
            view: 'emails.trial-signup-admin',
        );
    }
}
