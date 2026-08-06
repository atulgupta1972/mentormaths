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

    public function __construct(
        public TeacherRegistrationRequest $request,
        public bool $assignMentor = false,
        public bool $assignContentUploader = false,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->assignContentUploader
            ? 'Welcome to Mentor Maths — content uploader account approved'
            : 'Welcome to Mentor Maths — teacher account approved';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.teacher-registration-approved',
            with: [
                'loginUrl' => route('login'),
                'contentTasksUrl' => route('content.tasks.index'),
            ],
        );
    }
}
