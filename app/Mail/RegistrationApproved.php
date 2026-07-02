<?php

namespace App\Mail;

use App\Models\RegistrationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public RegistrationRequest $registrationRequest,
        public string $loginEmail,
        public string $loginPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Maths Foundation account is ready',
        );
    }

    public function content(): Content
    {
        $this->registrationRequest->loadMissing(['academicYear', 'gradeLevel']);

        return new Content(
            view: 'emails.registration-approved',
            with: [
                'loginUrl' => route('login'),
            ],
        );
    }
}
