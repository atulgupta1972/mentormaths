<?php

namespace Tests\Unit;

use App\Services\StudentNotificationContactService;
use App\Services\StudentProgressWhatsAppService;
use Tests\TestCase;

class MonthReportWhatsAppTest extends TestCase
{
    public function test_report_lists_logins_chapters_score_and_work_below_80(): void
    {
        $message = (new StudentProgressWhatsAppService(new StudentNotificationContactService))
            ->buildMonthReportMessage([
                'student_name' => 'Vishvesh',
                'class_name' => 'Class 7',
                'period_label' => '1 Nov 2026 – 10 Nov 2026',
                'dashboard_url' => 'https://mentormaths.in/dashboard',
                'engagement' => [
                    'days_logged_in' => 8,
                    'total_days' => 10,
                ],
                'stats' => [
                    'completed_count' => 3,
                    'overall_score_label' => '75% (30/40)',
                ],
                'chapter_performance' => [
                    [
                        'chapter_name' => 'Fractions',
                        'sets_count' => 2,
                        'label' => '90% (18/20)',
                    ],
                    [
                        'chapter_name' => 'Integers',
                        'sets_count' => 1,
                        'label' => '60% (12/20)',
                    ],
                ],
                'completed' => [
                    [
                        'chapter_name' => 'Fractions',
                        'set_code' => 'F1',
                        'latest_score_percent' => 90,
                        'latest_score_label' => '90% (9/10)',
                    ],
                    [
                        'chapter_name' => 'Fractions',
                        'set_code' => 'F2',
                        'latest_score_percent' => 90,
                        'latest_score_label' => '90% (9/10)',
                    ],
                    [
                        'chapter_name' => 'Integers',
                        'set_code' => 'I1',
                        'latest_score_percent' => 60,
                        'latest_score_label' => '60% (12/20)',
                    ],
                ],
            ]);

        $this->assertStringContainsString('Period: 1 Nov 2026 – 10 Nov 2026', $message);
        $this->assertStringContainsString('Logged in: 8 days', $message);
        $this->assertStringContainsString('Worksheets done: 3', $message);
        $this->assertStringContainsString('Fractions: 2 worksheets, score 90% (18/20)', $message);
        $this->assertStringContainsString('Integers: 1 worksheet, score 60% (12/20)', $message);
        $this->assertStringContainsString('Performance score: 75% (30/40)', $message);
        $this->assertStringContainsString('Improvement area (below 80%):', $message);
        $this->assertStringContainsString('Integers I1 60% (12/20)', $message);
        $this->assertStringNotContainsString('Fractions F1', $message);
    }

    public function test_report_says_when_nothing_is_below_80(): void
    {
        $message = (new StudentProgressWhatsAppService(new StudentNotificationContactService))
            ->buildMonthReportMessage([
                'student_name' => 'Vishvesh',
                'period_label' => '1 Nov 2026 – 16 Nov 2026',
                'dashboard_url' => 'https://mentormaths.in/dashboard',
                'engagement' => [
                    'days_logged_in' => 0,
                ],
                'stats' => [
                    'completed_count' => 0,
                ],
                'chapter_performance' => [],
                'completed' => [],
            ]);

        $this->assertStringContainsString('Logged in: 0 days', $message);
        $this->assertStringContainsString('Worksheets done: 0', $message);
        $this->assertStringContainsString('Chapter wise: none', $message);
        $this->assertStringContainsString('Performance score: no worksheets completed', $message);
        $this->assertStringContainsString('Improvement area: no worksheets to review', $message);
    }
}
