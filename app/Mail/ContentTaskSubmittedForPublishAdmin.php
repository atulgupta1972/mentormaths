<?php

namespace App\Mail;

use App\Models\ContentUploadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContentTaskSubmittedForPublishAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContentUploadTask $task) {}

    public function envelope(): Envelope
    {
        $chapter = $this->task->textbookChapter;
        $kind = match (true) {
            $this->task->isConceptPathBuild() => 'Concept builder done',
            $this->task->isFillBlankConversion() => 'Fill-blank ready',
            default => 'Publish ready',
        };

        return new Envelope(
            subject: "Mentor Maths — {$kind} · Ch {$chapter->chapter_number} · {$this->task->assignee->name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.content-task-submitted-admin');
    }
}
