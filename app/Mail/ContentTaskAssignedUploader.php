<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ContentTaskAssignedUploader extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, \App\Models\ContentUploadTask>  $tasks
     */
    public function __construct(
        public User $uploader,
        public Collection $tasks,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->tasks->count();

        if ($count === 1) {
            $task = $this->tasks->first();
            $chapter = $task?->textbookChapter;
            $grade = $chapter?->textbook?->gradeLevel?->name;
            $chNo = $chapter?->chapter_number;
            $title = $chapter?->title;

            if ($grade && $chNo) {
                $shortTitle = $title ? ' — '.$title : '';
                $kind = $task->isFillBlankConversion() ? 'fill-in-blank conversion assigned' : 'MCQ upload assigned';

                return new Envelope(
                    subject: "Mentor Maths — {$grade} Ch {$chNo}{$shortTitle} · {$kind}",
                );
            }
        }

        $label = $count === 1
            ? '1 chapter assigned'
            : "{$count} chapters assigned";

        return new Envelope(
            subject: "Mentor Maths — {$label} · textbook MCQ upload · please review & agree",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-task-assigned-uploader',
            with: [
                'tasksUrl' => route('content.tasks.index'),
                'loginUrl' => route('login'),
                'guideUrl' => url('/guides/content-uploader-guide.html'),
            ],
        );
    }
}
