<?php

namespace App\Mail;

use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentDailyBalanceReminder extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public Student $student,
        public array $summary,
    ) {}

    public function envelope(): Envelope
    {
        $stats = $this->summary['stats'];

        return new Envelope(
            subject: "Mentor Maths — {$stats['balance_count']} item(s) to do for {$this->summary['student_name']} ({$this->summary['as_of_label']})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.student-daily-balance-reminder',
            with: [
                'studentName' => $this->summary['student_name'],
                'summary' => $this->summary,
            ],
        );
    }
}
