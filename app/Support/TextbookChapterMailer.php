<?php

namespace App\Support;

use App\Mail\TextbookChapterExtracted;
use App\Mail\TextbookChapterExtractionFailed;
use App\Models\TextbookChapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TextbookChapterMailer
{
    /**
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function sendExtracted(TextbookChapter $chapter): array
    {
        return self::send($chapter, new TextbookChapterExtracted(TextbookChapterSummary::forEmail($chapter)));
    }

    /**
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function sendExtractionFailed(TextbookChapter $chapter): array
    {
        return self::send($chapter, new TextbookChapterExtractionFailed(TextbookChapterSummary::forFailedEmail($chapter)));
    }

    /**
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    private static function send(TextbookChapter $chapter, TextbookChapterExtracted|TextbookChapterExtractionFailed $mailable): array
    {
        $chapter->loadMissing('creator');

        $uploaderEmail = filled($chapter->creator?->email) ? $chapter->creator->email : null;
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $uploaderEmail && ! $adminEmail) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        try {
            $recipient = $uploaderEmail ?: $adminEmail;
            $pending = Mail::to($recipient);

            if ($uploaderEmail && $adminEmail && strcasecmp($adminEmail, $uploaderEmail) !== 0) {
                $pending->cc($adminEmail);
            }

            $pending->send($mailable);

            return ['sent' => true, 'email' => $recipient, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send textbook chapter extraction email.', [
                'textbook_chapter_id' => $chapter->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $uploaderEmail ?: $adminEmail, 'error' => 'send_failed'];
        }
    }
}
