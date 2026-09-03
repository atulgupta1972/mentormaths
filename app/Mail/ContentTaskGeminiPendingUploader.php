<?php

namespace App\Mail;

use App\Models\User;
use App\Models\ContentUploadTask;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ContentTaskGeminiPendingUploader extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, ContentUploadTask>  $tasks
     */
    public function __construct(
        public User $uploader,
        public Collection $tasks,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->tasks->count();

        $subject = $count === 1
            ? 'Mentor Maths — Gemini check pending for 1 content task'
            : "Mentor Maths — Gemini checks pending for {$count} content tasks";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-task-gemini-pending-uploader',
            with: [
                'tasksUrl' => route('content.tasks.index'),
                'loginUrl' => route('login'),
                'guideUrl' => url('/guides/content-uploader-guide.html'),
            ],
        );
    }
}

