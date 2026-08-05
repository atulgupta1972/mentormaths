<?php

namespace Tests\Unit;

use App\Support\FillBlankAnswerConsistency;
use PHPUnit\Framework\TestCase;

class FillBlankAnswerConsistencyTest extends TestCase
{
    public function test_detects_mismatch_between_stored_answer_and_explanation(): void
    {
        $checker = new FillBlankAnswerConsistency;

        $result = $checker->mismatch(
            '-24',
            'x³+3x²-4x-12 = (x+3)(x-2)(x+2). Product of constants = 3×(-2)×2 = -12. [Correction: product = -12]',
            'integer',
        );

        $this->assertNotNull($result);
        $this->assertSame('-12', $result['suggested_answer']);
    }

    public function test_passes_when_stored_answer_matches_explanation(): void
    {
        $checker = new FillBlankAnswerConsistency;

        $result = $checker->mismatch(
            '-12',
            'Product of constants = 3×(-2)×2 = -12.',
            'integer',
        );

        $this->assertNull($result);
    }
}
