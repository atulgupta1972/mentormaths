<?php

namespace Tests\Unit;

use App\Services\ClassCoverageService;
use App\Services\StudentProgressWhatsAppService;
use Tests\TestCase;

class StudyPlanWhatsAppStatusTest extends TestCase
{
    public function test_study_plan_performance_aggregates_tracked_chapters_only(): void
    {
        $coverage = [
            'chapters' => [
                [
                    'id' => 1,
                    'chapter_number' => '2',
                    'name' => 'Integers',
                    'studied' => true,
                    'under_study' => false,
                    'items' => [
                        'layout' => 'tier_blocks',
                        'blocks' => [
                            [
                                'rows' => [
                                    [
                                        'items' => [
                                            [
                                                'status' => 'done',
                                                'score_percent' => 80,
                                                'question_count' => 10,
                                                'pool_metrics' => [
                                                    'pool' => 10,
                                                    'attempted' => 10,
                                                    'correct' => 8,
                                                ],
                                                'is_correction' => false,
                                                'correction_count' => 0,
                                                'can_redo_wrong' => false,
                                            ],
                                            [
                                                'status' => 'pending',
                                                'score_percent' => null,
                                                'question_count' => 10,
                                                'pool_metrics' => [
                                                    'pool' => 10,
                                                    'attempted' => 0,
                                                    'correct' => 0,
                                                ],
                                                'is_correction' => false,
                                                'correction_count' => 2,
                                                'can_redo_wrong' => true,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'formula' => ['items' => []],
                        'practice_correction' => [
                            'items' => [
                                ['status' => 'done', 'is_correction' => true],
                                ['status' => 'not_done', 'is_correction' => true],
                            ],
                        ],
                        'books' => ['items' => []],
                    ],
                ],
                [
                    'id' => 2,
                    'chapter_number' => '3',
                    'name' => 'Fractions',
                    'studied' => false,
                    'under_study' => false,
                    'items' => [
                        'layout' => 'tier_blocks',
                        'blocks' => [
                            [
                                'rows' => [
                                    [
                                        'items' => [
                                            ['status' => 'done', 'score_percent' => 100, 'is_correction' => false, 'correction_count' => 0, 'can_redo_wrong' => false],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'formula' => ['items' => []],
                        'practice_correction' => ['items' => []],
                        'books' => ['items' => []],
                    ],
                ],
            ],
        ];

        $perf = app(ClassCoverageService::class)->studyPlanPerformanceFromCoverage($coverage);

        $this->assertNotNull($perf);
        $this->assertSame(1, $perf['chapter_count']);
        $this->assertSame(['Ch 2'], $perf['chapter_labels']);
        $this->assertSame(20, $perf['total']);
        $this->assertSame(10, $perf['done']);
        $this->assertSame(8, $perf['correct']);
        $this->assertSame(50, $perf['completion_pct']);
        $this->assertSame(40, $perf['score_pct']);
        $this->assertSame(1, $perf['correction_done']);
        $this->assertSame(1, $perf['correction_pending']);
        $this->assertSame(2, $perf['open_wrongs']);
    }

    public function test_study_plan_whatsapp_message_includes_overall_status(): void
    {
        $message = app(StudentProgressWhatsAppService::class)->buildStudyPlanMessage([
            'student_name' => 'Vishvesh',
            'class_name' => 'Class 7',
            'as_of_label' => '22 Aug 2026',
            'dashboard_url' => 'https://mentormaths.in/dashboard',
            'pending_count' => 1,
            'overdue_count' => 2,
            'study_plan' => [
                'total' => 50,
                'done' => 20,
                'correct' => 42,
                'completion_pct' => 40,
                'score_pct' => 84,
                'scored_count' => 42,
                'correction_done' => 1,
                'correction_pending' => 2,
                'open_wrongs' => 3,
                'chapter_count' => 2,
                'chapter_labels' => ['Ch 2', 'Ch 5'],
            ],
        ]);

        $this->assertStringContainsString('Study plan status for Vishvesh', $message);
        $this->assertStringContainsString('Completion: 20/50 sums (40%)', $message);
        $this->assertStringContainsString('Score: 42/50 first-try (84%)', $message);
        $this->assertStringContainsString('Corrections: 1 done, 2 pending', $message);
        $this->assertStringContainsString('2 overdue, 1 pending', $message);
        $this->assertStringContainsString('https://mentormaths.in/dashboard', $message);
    }
}
