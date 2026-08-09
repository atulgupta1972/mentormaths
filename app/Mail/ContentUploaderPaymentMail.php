<?php

namespace App\Mail;

use App\Models\ContentUploaderPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContentUploaderPaymentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContentUploaderPayment $payment,
    ) {}

    public function envelope(): Envelope
    {
        $chapter = $this->payment->task?->textbookChapter;
        $grade = $chapter?->textbook?->gradeLevel?->name ?? 'Class';
        $chNo = $chapter?->chapter_number ?? '?';
        $amount = number_format((int) $this->payment->amount_inr);

        return new Envelope(
            subject: "Mentor Maths — payment of ₹{$amount} for {$grade} Ch {$chNo}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-uploader-payment',
            with: [
                'loginUrl' => route('login'),
                'tasksUrl' => route('content.tasks.index'),
            ],
        );
    }
}
