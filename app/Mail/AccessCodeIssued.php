<?php

namespace App\Mail;

use App\Models\AccessCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccessCodeIssued extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AccessCode $accessCode,
        public string $loginEmail,
        public string $plainCode,
        public string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Mentor Maths access code (tcode)',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.access-code-issued',
            with: [
                'loginUrl' => route('login'),
                'expiresOn' => $this->accessCode->expires_at
                    ?->timezone(config('app.timezone'))
                    ->format('d M Y'),
            ],
        );
    }
}
