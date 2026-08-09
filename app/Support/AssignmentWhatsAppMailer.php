<?php

namespace App\Support;

use App\Models\Student;
use App\Models\Worksheet;
use App\Services\AssignmentWhatsAppNotificationService;
use App\Support\WhatsApp\WhatsAppSender;

class AssignmentWhatsAppMailer
{
    /**
     * @param  list<Worksheet>  $worksheets
     * @return array{sent_count: int, failed_count: int, skipped_count: int, results: list<array<string, mixed>>, error: ?string}
     */
    public static function sendAssignedMany(
        Student $student,
        array $worksheets,
        string $dueDate,
        ?string $notes = null,
    ): array {
        if ($worksheets === []) {
            return self::emptyResult('no_worksheets');
        }

        $service = app(AssignmentWhatsAppNotificationService::class);

        $notifications = count($worksheets) === 1
            ? $service->notificationsForAssignment($student, $worksheets[0], $dueDate, $notes)
            : $service->notificationsForMultiAssignment($student, $worksheets, $dueDate, $notes);

        if ($notifications === []) {
            return self::emptyResult('no_recipients');
        }

        $result = WhatsAppSender::sendNotifications('assignment_assigned', $notifications);
        $result['error'] = null;

        return $result;
    }

    /**
     * @return array{sent_count: int, failed_count: int, skipped_count: int, results: list<array<string, mixed>>, error: ?string}
     */
    private static function emptyResult(?string $error): array
    {
        return [
            'sent_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'results' => [],
            'error' => $error,
        ];
    }
}
