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
        $isMentor = $this->accessCode->type === AccessCode::TYPE_MENTOR;

        return new Envelope(
            subject: $isMentor
                ? 'Your Mentor Maths tcode + next steps'
                : 'Your Mentor Maths access code (tcode)',
        );
    }

    public function content(): Content
    {
        $isMentor = $this->accessCode->type === AccessCode::TYPE_MENTOR;
        $expiresOn = $this->accessCode->expires_at
            ?->timezone(config('app.timezone'))
            ->format('d M Y');

        if ($isMentor) {
            return new Content(
                view: 'emails.mentor-access-welcome',
                with: [
                    'loginUrl' => route('login'),
                    'expiresOn' => $expiresOn,
                    'registerUrl' => route('registration.create'),
                    'classesUrl' => route('mentor.classes.index'),
                    'coverageUrl' => route('admin.questions.coverage'),
                ],
            );
        }

        return new Content(
            view: 'emails.access-code-issued',
            with: [
                'loginUrl' => route('login'),
                'expiresOn' => $expiresOn,
            ],
        );
    }
}
