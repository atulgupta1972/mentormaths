<?php

namespace App\Support;

use App\Models\Student;
use App\Services\StudentProgressWhatsAppService;
use App\Support\WhatsApp\WhatsAppSender;

class StudentStudyPlanWhatsAppMailer
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array{sent_count: int, failed_count: int, skipped_count: int, results: list<array<string, mixed>>, error: ?string}
     */
    public static function send(Student $student, array $summary): array
    {
        $notifications = app(StudentProgressWhatsAppService::class)
            ->notificationsForStudyPlanStatus($student, $summary);

        if ($notifications === []) {
            return [
                'sent_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 0,
                'results' => [],
                'error' => 'no_recipients',
            ];
        }

        $result = WhatsAppSender::sendNotifications('study_plan_status', $notifications);
        $result['error'] = null;

        return $result;
    }
}
