<?php

namespace App\Mail;

use App\Models\RegistrationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewRegistrationRequestAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RegistrationRequest $registrationRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New registration request — Mentor Maths',
        );
    }

    public function content(): Content
    {
        $this->registrationRequest->loadMissing(['academicYear', 'board', 'gradeLevel']);

        return new Content(
            view: 'emails.new-registration-admin',
            with: [
                'reviewUrl' => route('admin.registration-requests.show', $this->registrationRequest),
            ],
        );
    }
}
