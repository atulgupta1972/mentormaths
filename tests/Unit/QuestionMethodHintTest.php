<?php

namespace Tests\Unit;

use App\Support\QuestionMethodHint;
use Tests\TestCase;

class QuestionMethodHintTest extends TestCase
{
    public function test_infer_integer_multiplication_sign_rules_without_final_answer(): void
    {
        $hint = QuestionMethodHint::inferFromQuestionText('(-4) × (-3) × (-2) = ______');

        $this->assertNotNull($hint);
        $this->assertStringContainsString('negative × negative', strtolower($hint));
        $this->assertStringNotContainsString('-24', $hint);
        $this->assertStringNotContainsString('answer key', strtolower($hint));
    }

    public function test_sanitize_explanation_removes_answer_key(): void
    {
        $clean = QuestionMethodHint::sanitizeExplanation('(-4) × (-3) = 12. Answer key: c.');

        $this->assertSame('(-4) × (-3) = 12', $clean);
    }
}
