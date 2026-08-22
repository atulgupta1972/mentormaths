<?php

namespace App\Mail;

use App\Models\QuestionIssueReport;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuestionCorrectReattempt extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Student $student,
        public QuestionIssueReport $report,
        public string $questionPreview,
        public ?string $setCode = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mentor Maths — Question is correct; please re-attempt (0 marks)',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.question-correct-reattempt',
            with: [
                'studentName' => $this->student->name,
                'questionPreview' => $this->questionPreview,
                'setCode' => $this->setCode,
                'contextLabel' => match ($this->report->context) {
                    QuestionIssueReport::CONTEXT_BATCH => 'Chapter test',
                    QuestionIssueReport::CONTEXT_FORMULA_DRILL => 'Formula drill',
                    default => 'Guided practice',
                },
                'dashboardUrl' => route('dashboard'),
                'loginUrl' => route('login'),
            ],
        );
    }
}
