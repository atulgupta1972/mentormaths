<?php

namespace Tests\Unit;

use App\Support\PublisherNameStripper;
use PHPUnit\Framework\TestCase;

class PublisherNameStripperTest extends TestCase
{
    public function test_it_removes_rd_sharma_and_rs_aggarwal_names(): void
    {
        $stripper = new PublisherNameStripper;

        $this->assertSame(
            '△ABO ≅ △ODC is written as the match.',
            $stripper->strip('In RD Sharma, △ABO ≅ △ODC is written as the match.'),
        );
        $this->assertSame(
            'table key, answer is 4.',
            $stripper->strip('Based on RD Sharma table key, answer is 4.'),
        );
        $this->assertSame(
            'the mean is ____.',
            $stripper->strip('In RS Aggarwal the mean is ____.'),
        );
        $this->assertSame(
            'the mean is 4.',
            $stripper->strip('R.S. Agarwal: the mean is 4.'),
        );
    }
}
