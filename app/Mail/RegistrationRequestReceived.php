<?php

namespace App\Mail;

use App\Models\RegistrationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RegistrationRequestReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RegistrationRequest $registrationRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Registration request received — Maths Foundation',
        );
    }

    public function content(): Content
    {
        $this->registrationRequest->loadMissing(['academicYear', 'board', 'gradeLevel']);

        return new Content(
            view: 'emails.registration-received',
        );
    }
}
