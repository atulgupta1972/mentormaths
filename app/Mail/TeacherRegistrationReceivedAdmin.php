<?php

namespace App\Mail;

use App\Models\TeacherRegistrationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeacherRegistrationReceivedAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TeacherRegistrationRequest $request) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New teacher application — Mentor Maths',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.teacher-registration-received-admin');
    }
}
