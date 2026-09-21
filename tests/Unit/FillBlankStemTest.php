<?php

namespace Tests\Unit;

use App\Support\FillBlankStem;
use PHPUnit\Framework\TestCase;

class FillBlankStemTest extends TestCase
{
    public function test_normalizes_underscore_variants(): void
    {
        $this->assertSame(
            'x = ____',
            FillBlankStem::normalize('x = ___'),
        );
        $this->assertSame(
            'x = ____',
            FillBlankStem::normalize('x = ______'),
        );
        $this->assertTrue(FillBlankStem::hasBlank('x = ___'));
    }

    public function test_detects_missing_blank(): void
    {
        $this->assertFalse(FillBlankStem::hasBlank('Find the value of x.'));
        $this->assertTrue(FillBlankStem::hasBlank('Find the value of x = ____.'));
    }
}
