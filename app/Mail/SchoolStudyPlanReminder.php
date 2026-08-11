<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SchoolStudyPlanReminder extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{student_name: string, grade_name: ?string, dashboard_url: string}  $payload
     */
    public function __construct(
        public Student $student,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->payload['student_name'] ?? $this->student->name;

        return new Envelope(
            subject: "Mentor Maths — please mark your school study plan ({$name})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.school-study-plan-reminder',
            with: [
                'studentName' => $this->payload['student_name'],
                'gradeName' => $this->payload['grade_name'] ?? null,
                'dashboardUrl' => $this->payload['dashboard_url'],
            ],
        );
    }
}
