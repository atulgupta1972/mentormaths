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
        $label = $count === 1
            ? '1 chapter assigned'
            : "{$count} chapters assigned";

        return new Envelope(
            subject: "Mentor Maths — {$label} · please review & agree",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-task-assigned-uploader',
            with: [
                'tasksUrl' => route('content.tasks.index'),
                'loginUrl' => route('login'),
            ],
        );
    }
}
