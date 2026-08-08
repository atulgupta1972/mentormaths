<?php

namespace App\Mail;

use App\Models\ContentUploadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContentTaskReturnedUploader extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContentUploadTask $task,
    ) {}

    public function envelope(): Envelope
    {
        $chapter = $this->task->textbookChapter;
        $grade = $chapter?->textbook?->gradeLevel?->name ?? 'Class';
        $chNo = $chapter?->chapter_number ?? '?';

        return new Envelope(
            subject: "Mentor Maths — {$grade} Ch {$chNo} · please re-verify MCQ options",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-task-returned-uploader',
            with: [
                'taskUrl' => route('content.tasks.show', $this->task),
                'loginUrl' => route('login'),
            ],
        );
    }
}
