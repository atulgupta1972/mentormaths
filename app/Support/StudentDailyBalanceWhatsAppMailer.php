<?php

namespace App\Support;

use App\Models\Student;
use App\Services\StudentProgressWhatsAppService;
use App\Support\WhatsApp\WhatsAppSender;

class StudentDailyBalanceWhatsAppMailer
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array{sent_count: int, failed_count: int, skipped_count: int, results: list<array<string, mixed>>, error: ?string}
     */
    public static function send(Student $student, array $summary): array
    {
        $notifications = app(StudentProgressWhatsAppService::class)
            ->notificationsForBalanceReminder($student, $summary);

        if ($notifications === []) {
            return [
                'sent_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'results' => [],
                'error' => 'no_recipients',
            ];
        }

        $result = WhatsAppSender::sendNotifications('daily_balance', $notifications);
        $result['error'] = null;

        return $result;
    }
}
