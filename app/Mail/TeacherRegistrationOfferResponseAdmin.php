<?php

namespace App\Mail;

use App\Models\TeacherRegistrationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeacherRegistrationOfferResponseAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TeacherRegistrationRequest $request) {}

    public function envelope(): Envelope
    {
        $label = $request->offer_response === TeacherRegistrationRequest::OFFER_ACCEPTED
            ? 'accepted'
            : 'declined';

        return new Envelope(
            subject: "Teacher {$label} your counter offer — Mentor Maths",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.teacher-registration-offer-response-admin');
    }
}
