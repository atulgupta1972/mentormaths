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

    public function test_simple_fraction_in_explanation_is_not_truncated_to_numerator(): void
    {
        $checker = new FillBlankAnswerConsistency;

        $result = $checker->mismatch(
            '11/3',
            'Substitute and simplify. The value of the expression is 11/3.',
            'fraction',
        );

        $this->assertNull($result);
    }

    public function test_fraction_answer_still_mismatches_when_explanation_ends_on_different_integer(): void
    {
        $checker = new FillBlankAnswerConsistency;

        $result = $checker->mismatch(
            '11/3',
            'Work shown above. Final answer is 11.',
            'fraction',
        );

        $this->assertNotNull($result);
        $this->assertSame('11', $result['suggested_answer']);
    }
}
