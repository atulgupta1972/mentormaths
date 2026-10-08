<?php

namespace App\Mail;

use App\Models\ContentUploadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContentConceptPathReturnedUploader extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{step?: int|null, title?: string|null, remark: string}>  $returnItems
     */
    public function __construct(
        public ContentUploadTask $task,
        public array $returnItems = [],
    ) {}

    public function envelope(): Envelope
    {
        $chapter = $this->task->textbookChapter;
        $grade = $chapter?->textbook?->gradeLevel?->name ?? 'Class';
        $chNo = $chapter?->displayChapterNumber() ?? ($chapter?->chapter_number ?? '?');
        $count = count($this->returnItems);
        $suffix = $count > 0
            ? " · {$count} concept card".($count === 1 ? '' : 's').' to fix'
            : ' · concept path needs changes';

        return new Envelope(
            subject: "Mentor Maths — {$grade} {$chNo}{$suffix}",
        );
    }

    public function content(): Content
    {
        $chapter = $this->task->textbookChapter;

        return new Content(
            view: 'emails.content-concept-path-returned-uploader',
            with: [
                'taskUrl' => $chapter
                    ? route('content.textbooks.concept-path', $chapter)
                    : route('content.tasks.show', $this->task),
                'loginUrl' => route('login'),
                'returnItems' => $this->returnItems,
            ],
        );
    }
}
