<?php

namespace App\Support;

use App\Models\Student;
use App\Services\AttemptCompletionWhatsAppNotificationService;
use App\Support\WhatsApp\WhatsAppSender;

class AttemptCompletionWhatsAppMailer
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array{sent_count: int, failed_count: int, skipped_count: int, results: list<array<string, mixed>>, error: ?string}
     */
    public static function sendCompleted(Student $student, array $summary): array
    {
        $notifications = app(AttemptCompletionWhatsAppNotificationService::class)
            ->notificationsForCompletion($student, $summary);

        if ($notifications === []) {
            return [
                'sent_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'results' => [],
                'error' => 'no_recipients',
            ];
        }

        $result = WhatsAppSender::sendNotifications('assignment_completed', $notifications);
        $result['error'] = null;

        return $result;
    }
}
