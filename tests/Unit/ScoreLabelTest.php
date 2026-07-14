<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ScoreLabelTest extends TestCase
{
    public function test_format_includes_percent_and_fraction(): void
    {
        $this->assertSame('65% (13/20)', \App\Support\ScoreLabel::format(13, 20));
    }

    public function test_aggregate_overall_score(): void
    {
        $overall = \App\Support\ScoreLabel::aggregateFromRows([
            ['latest_score' => 13, 'latest_max_score' => 20],
            ['latest_score' => 18, 'latest_max_score' => 20],
        ]);

        $this->assertSame(31, $overall['score_total']);
        $this->assertSame(40, $overall['max_total']);
        $this->assertSame(78, $overall['percent']);
        $this->assertSame('78% (31/40)', $overall['label']);
    }
}
