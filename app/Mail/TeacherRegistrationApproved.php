<?php

namespace App\Mail;

use App\Models\TeacherRegistrationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeacherRegistrationApproved extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TeacherRegistrationRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Mentor Maths — teacher account approved',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.teacher-registration-approved');
    }
}
