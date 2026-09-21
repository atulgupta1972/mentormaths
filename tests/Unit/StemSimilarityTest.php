<?php

namespace Tests\Unit;

use App\Support\StemSimilarity;
use PHPUnit\Framework\TestCase;

class StemSimilarityTest extends TestCase
{
    public function test_rejects_near_copy_wording(): void
    {
        $svc = new StemSimilarity;
        $source = 'Ramesh scored 67, 55, 18 and 35 runs. Find the mean.';
        $near = 'Ramesh scored 67, 55, 18 and 35 runs. The mean is ____.';

        $result = $svc->compare($source, $near);

        $this->assertTrue($result['too_similar']);
        $this->assertNotNull($result['reason']);
    }

    public function test_accepts_rewritten_stem_with_new_numbers(): void
    {
        $svc = new StemSimilarity;
        $source = 'Ramesh scored 67, 55, 18 and 35 runs. Find the mean.';
        $rewritten = 'A batsman scored 72, 48, 21 and 39 runs in four matches. The mean score is ____.';

        $result = $svc->compare($source, $rewritten);

        $this->assertFalse($result['too_similar']);
        $this->assertNull($result['reason']);
    }

    public function test_accepts_strong_rewrite_even_if_numbers_match(): void
    {
        $svc = new StemSimilarity;
        $source = 'Ramesh scored 67, 55, 18 and 35 runs. Find the mean.';
        $rewritten = 'Compute the arithmetic average of the four match totals 67, 55, 18 and 35. Mean = ____.';

        $result = $svc->compare($source, $rewritten);

        $this->assertFalse($result['too_similar']);
    }

    public function test_rejects_publisher_brand_in_stem(): void
    {
        $svc = new StemSimilarity;
        $result = $svc->compare(
            'Find x.',
            'As in RD Sharma exercise 5.2, x equals ____.',
        );

        $this->assertTrue($result['too_similar']);
        $this->assertSame('rd sharma', $result['banned_hit']);
    }
}
