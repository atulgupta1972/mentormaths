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

    /**
     * @param  list<array{question_id: int, remark?: string, number?: int|null, question_text?: ?string}>  $returnItems
     */
    public function __construct(
        public ContentUploadTask $task,
        public array $returnItems = [],
    ) {}

    public function envelope(): Envelope
    {
        $chapter = $this->task->textbookChapter;
        $grade = $chapter?->textbook?->gradeLevel?->name ?? 'Class';
        $chNo = $chapter?->chapter_number ?? '?';
        $count = count($this->returnItems);
        $suffix = $count > 0
            ? " · {$count} question".($count === 1 ? '' : 's').' to fix'
            : ' · please re-verify MCQ options';

        return new Envelope(
            subject: "Mentor Maths — {$grade} Ch {$chNo}{$suffix}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-task-returned-uploader',
            with: [
                'taskUrl' => route('content.tasks.show', $this->task),
                'loginUrl' => route('login'),
                'returnItems' => $this->returnItems,
            ],
        );
    }
}
