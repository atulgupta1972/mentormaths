<?php

namespace Tests\Unit;

use App\Services\StudentNotificationContactService;
use App\Services\StudentProgressWhatsAppService;
use Tests\TestCase;

class MonthReportWhatsAppTest extends TestCase
{
    public function test_month_report_message_includes_time_tests_and_score(): void
    {
        $message = (new StudentProgressWhatsAppService(new StudentNotificationContactService))
            ->buildMonthReportMessage([
                'student_name' => 'Vishvesh',
                'class_name' => 'Class 7',
                'period_label' => '1 Nov 2026 – 16 Nov 2026',
                'dashboard_url' => 'https://mentormaths.in/dashboard',
                'engagement' => [
                    'time_spent_label' => '3h 20m',
                    'days_logged_in' => 8,
                    'total_days' => 16,
                ],
                'stats' => [
                    'completed_count' => 4,
                    'overall_score_label' => '32/40 (80%)',
                ],
            ]);

        $this->assertStringContainsString('Monthly report for Vishvesh', $message);
        $this->assertStringContainsString('Time on Mentor Maths: 3h 20m', $message);
        $this->assertStringContainsString('Days logged in: 8 of 16', $message);
        $this->assertStringContainsString('Tests completed: 4', $message);
        $this->assertStringContainsString('Score: 32/40 (80%)', $message);
    }

    public function test_month_report_message_says_when_no_tests_were_completed(): void
    {
        $message = (new StudentProgressWhatsAppService(new StudentNotificationContactService))
            ->buildMonthReportMessage([
                'student_name' => 'Vishvesh',
                'period_label' => '1 Nov 2026 – 16 Nov 2026',
                'dashboard_url' => 'https://mentormaths.in/dashboard',
                'engagement' => [
                    'time_spent_label' => '0 sec',
                    'days_logged_in' => 0,
                    'total_days' => 16,
                ],
                'stats' => [
                    'completed_count' => 0,
                ],
            ]);

        $this->assertStringContainsString('Tests completed: 0', $message);
        $this->assertStringContainsString('Score: no tests completed', $message);
    }
}
