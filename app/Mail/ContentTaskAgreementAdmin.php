<?php

namespace App\Mail;

use App\Models\ContentUploadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContentTaskAgreementAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContentUploadTask $task) {}

    public function envelope(): Envelope
    {
        $chapter = $this->task->textbookChapter;

        return new Envelope(
            subject: "Mentor Maths — {$this->task->assignee->name} agreed · Ch {$chapter->chapter_number} · ₹{$this->task->agreed_amount_inr}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.content-task-agreement-admin');
    }
}
