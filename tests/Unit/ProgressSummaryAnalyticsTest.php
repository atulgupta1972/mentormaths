<?php

namespace Tests\Unit;

use App\Support\ProgressSummaryAnalytics;
use App\Support\ProgressSummaryChartImage;
use App\Support\ProgressSummaryChartSvg;
use Tests\TestCase;

class ProgressSummaryAnalyticsTest extends TestCase
{
    public function test_chapter_performance_aggregates_completed_rows(): void
    {
        $completed = [
            [
                'chapter_name' => 'Lines and Angles',
                'latest_score' => 8,
                'latest_max_score' => 10,
                'submitted_at' => '2026-07-10 10:00:00',
            ],
            [
                'chapter_name' => 'Lines and Angles',
                'latest_score' => 7,
                'latest_max_score' => 10,
                'submitted_at' => '2026-07-12 10:00:00',
            ],
            [
                'chapter_name' => 'Integers',
                'latest_score' => 9,
                'latest_max_score' => 10,
                'submitted_at' => '2026-07-11 10:00:00',
            ],
        ];

        $chapters = ProgressSummaryAnalytics::chapterPerformance($completed);

        $this->assertCount(2, $chapters);
        $this->assertSame('Integers', $chapters[0]['chapter_name']);
        $this->assertSame('Lines and Angles', $chapters[1]['chapter_name']);
        $this->assertSame(75, $chapters[1]['percent']);
        $this->assertSame('75% (15/20)', $chapters[1]['label']);
        $this->assertSame(2, $chapters[1]['sets_count']);
    }

    public function test_date_performance_groups_by_submission_date(): void
    {
        $completed = [
            [
                'latest_score' => 4,
                'latest_max_score' => 5,
                'submitted_at' => '2026-07-10 09:00:00',
            ],
            [
                'latest_score' => 3,
                'latest_max_score' => 5,
                'submitted_at' => '2026-07-10 15:00:00',
            ],
            [
                'latest_score' => 9,
                'latest_max_score' => 10,
                'submitted_at' => '2026-07-12 10:00:00',
            ],
        ];

        $dates = ProgressSummaryAnalytics::datePerformance($completed);

        $this->assertCount(2, $dates);
        $this->assertSame('2026-07-10', $dates[0]['date']);
        $this->assertSame(70, $dates[0]['percent']);
        $this->assertSame(2, $dates[0]['sets_count']);
        $this->assertSame(90, $dates[1]['percent']);
    }

    public function test_chart_svg_generates_bar_and_line_markup(): void
    {
        $series = [
            ['label' => 'Chapter A', 'percent' => 80],
            ['label' => 'Chapter B', 'percent' => 65],
        ];

        $bar = ProgressSummaryChartSvg::barChart($series);
        $line = ProgressSummaryChartSvg::lineChart($series);

        $this->assertStringContainsString('<svg', $bar);
        $this->assertStringContainsString('<rect', $bar);
        $this->assertStringContainsString('<polyline', $line);
        $this->assertStringContainsString('<circle', $line);
    }

    public function test_chart_image_generates_png_data_uris_for_pdf(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $series = [
            ['label' => 'Chapter A', 'percent' => 80],
            ['label' => 'Chapter B', 'percent' => 65],
        ];

        $bar = ProgressSummaryChartImage::barChartDataUri($series);
        $line = ProgressSummaryChartImage::lineChartDataUri($series);

        $this->assertStringStartsWith('data:image/png;base64,', $bar);
        $this->assertStringStartsWith('data:image/png;base64,', $line);
    }
}
