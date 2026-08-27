<?php

namespace Tests\Unit;

use App\Support\SumPoolAggregate;
use Tests\TestCase;

class SumPoolAggregateTest extends TestCase
{
    public function test_aggregates_completion_and_score_by_sums_not_sets(): void
    {
        $items = [
            [
                'status' => 'done',
                'question_count' => 20,
                'pool_metrics' => ['pool' => 25, 'attempted' => 25, 'correct' => 20],
            ],
            [
                'status' => 'pending',
                'question_count' => 10,
                'pool_metrics' => ['pool' => 10, 'attempted' => 0, 'correct' => 0],
            ],
        ];

        $agg = SumPoolAggregate::fromItems($items);

        $this->assertSame(35, $agg['pool']);
        $this->assertSame(25, $agg['attempted']);
        $this->assertSame(20, $agg['correct']);
        $this->assertSame(71, $agg['completion_pct']); // 25/35
        $this->assertSame(57, $agg['score_pct']); // 20/35
        $this->assertSame(2, $agg['set_total']);
        $this->assertSame(1, $agg['set_done']);
    }
}
