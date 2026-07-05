<?php

namespace Tests\Unit;

use App\Support\AttemptTiming;
use Tests\TestCase;

class AttemptTimingTest extends TestCase
{
    public function test_elapsed_seconds_is_never_negative(): void
    {
        $startedAt = now()->addHour();

        $this->assertSame(3600, AttemptTiming::elapsedSeconds($startedAt));
    }

    public function test_elapsed_seconds_for_normal_attempt(): void
    {
        $startedAt = now()->subMinutes(5);

        $elapsed = AttemptTiming::elapsedSeconds($startedAt);

        $this->assertGreaterThanOrEqual(299, $elapsed);
        $this->assertLessThanOrEqual(301, $elapsed);
    }
}
