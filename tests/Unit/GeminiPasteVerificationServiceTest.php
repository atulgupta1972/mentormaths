<?php

namespace Tests\Unit;

use App\Services\GeminiPasteVerificationService;
use Tests\TestCase;

class GeminiPasteVerificationServiceTest extends TestCase
{
    private GeminiPasteVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GeminiPasteVerificationService::class);
    }

    public function test_parses_question_blocks_with_status_correct(): void
    {
        $paste = <<<'TEXT'
Question 14 Analysis:
Current Answer: 2
Status: Correct
Note: Simplifies to two terms.

Question 17 Analysis:
Current Answer: three, two or more
Status: Needs Verification
Note: ICSE defines trinomial as exactly three terms.
TEXT;

        $parsed = $this->service->parseGeminiOutput($paste);

        $this->assertSame('correct', $parsed[14]['status']);
        $this->assertStringContainsString('two terms', $parsed[14]['note']);
        $this->assertSame('needs_attention', $parsed[17]['status']);
        $this->assertStringContainsString('trinomial', $parsed[17]['note']);
    }

    public function test_parses_q_prefix_and_skip_status(): void
    {
        $paste = <<<'TEXT'
Q3
Status: Skip
Note: Not from this chapter.

Q4
Status: Correct
TEXT;

        $parsed = $this->service->parseGeminiOutput($paste);

        $this->assertSame('skip', $parsed[3]['status']);
        $this->assertSame('correct', $parsed[4]['status']);
    }

    public function test_build_prompt_lists_pending_questions(): void
    {
        $prompt = $this->service->buildPrompt([
            [
                'number' => 1,
                'set_code' => 'P1',
                'question_text' => 'What is 2 + 2?',
                'options' => [
                    ['letter' => 'A', 'option_text' => '3', 'is_correct' => false],
                    ['letter' => 'B', 'option_text' => '4', 'is_correct' => true],
                ],
                'method_hint' => 'Add',
                'explanation' => 'Basic addition',
            ],
        ], 'Class 7 · GP · Ch 12');

        $this->assertStringContainsString('Question 1 [MCQ]', $prompt);
        $this->assertStringContainsString('What is 2 + 2?', $prompt);
        $this->assertStringContainsString('B. 4 [CORRECT]', $prompt);
        $this->assertStringContainsString('Status: Correct', $prompt);
    }

    public function test_build_prompt_includes_fill_blank_answer(): void
    {
        $prompt = $this->service->buildPrompt([
            [
                'number' => 3,
                'is_fill_in_blank' => true,
                'question_type' => 'fill_in_blank',
                'question_text' => '8 ÷ (-2) = ____',
                'correct_answer' => '-4',
                'answer_format' => 'integer',
                'options' => [],
                'method_hint' => 'Evaluate brackets first.',
                'explanation' => '8÷(-2)=-4',
            ],
        ], 'Class 7 · Integers');

        $this->assertStringContainsString('Question 3 [Fill in blank]', $prompt);
        $this->assertStringContainsString('Fill-blank answer: -4 (format: integer)', $prompt);
        $this->assertStringContainsString('8 ÷ (-2) = ____', $prompt);
        $this->assertStringNotContainsString('reviewing MCQ answers', $prompt);
    }
}
