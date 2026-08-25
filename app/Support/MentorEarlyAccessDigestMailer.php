<?php

namespace App\Support;

use App\Mail\MentorEarlyAccessDigest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MentorEarlyAccessDigestMailer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{sent: bool, to: ?string, error: ?string}
     */
    public static function send(User $mentor, array $payload): array
    {
        $email = $mentor->email;

        if (! AssignmentMailer::isDeliverableEmail($email)) {
            return ['sent' => false, 'to' => null, 'error' => 'no_email'];
        }

        try {
            Mail::to($email)->send(new MentorEarlyAccessDigest($mentor, $payload));

            return ['sent' => true, 'to' => $email, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send mentor early-access digest.', [
                'mentor_id' => $mentor->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'to' => $email, 'error' => 'send_failed'];
        }
    }
}
