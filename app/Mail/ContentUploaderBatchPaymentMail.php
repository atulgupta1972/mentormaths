<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ContentUploaderBatchPaymentMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  Collection<int, \App\Models\ContentUploaderPayment>  $payments
     */
    public function __construct(
        public User $uploader,
        public Collection $payments,
    ) {}

    public function envelope(): Envelope
    {
        $total = number_format((int) $this->payments->sum('amount_inr'));
        $count = $this->payments->count();

        return new Envelope(
            subject: "Mentor Maths — payment of ₹{$total} for {$count} chapter".($count === 1 ? '' : 's'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-uploader-batch-payment',
            with: [
                'loginUrl' => route('login'),
                'tasksUrl' => route('content.tasks.index'),
            ],
        );
    }
}
