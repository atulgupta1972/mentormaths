<?php

namespace App\Mail;

use App\Models\ContentUploadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContentTaskAdditionalBookUploader extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContentUploadTask $task,
        public string $decisionLabel,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        $chapter = $this->task->textbookChapter;
        $grade = $chapter?->textbook?->gradeLevel?->name ?? 'Class';
        $chNo = $chapter?->chapter_number ?? '?';

        return new Envelope(
            subject: "Mentor Maths — {$grade} Ch {$chNo} — add another book",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-task-additional-book-uploader',
            with: [
                'taskUrl' => route('content.tasks.show', $this->task),
                'loginUrl' => route('login'),
                'decisionLabel' => $this->decisionLabel,
                'note' => $this->note,
            ],
        );
    }
}
