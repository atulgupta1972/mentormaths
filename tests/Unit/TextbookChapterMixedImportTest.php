<?php

namespace Tests\Unit;

use App\Services\TextbookChapterAnswerClassificationService;
use App\Services\TextbookChapterStagingGeminiService;
use Tests\TestCase;

class TextbookChapterMixedImportTest extends TestCase
{
    private TextbookChapterAnswerClassificationService $classification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classification = app(TextbookChapterAnswerClassificationService::class);
    }

    public function test_whole_number_becomes_fill_blank(): void
    {
        $item = $this->classification->applyMixedClassification([
            'question_text' => 'What is 12 + 7?',
            'correct_answer' => '19',
            'mcq_options' => [
                ['text' => '18', 'is_correct' => false],
                ['text' => '19', 'is_correct' => true],
            ],
        ]);

        $this->assertSame('fill_blank', $item['question_type']);
        $this->assertFalse($item['include_in_mcq']);
        $this->assertSame('19', $item['fill_blank_correct_answer']);
    }

    public function test_fraction_becomes_fill_blank(): void
    {
        $item = $this->classification->applyMixedClassification([
            'question_text' => 'Simplify 4/6',
            'correct_answer' => '2/3',
            'mcq_options' => [],
        ]);

        $this->assertSame('fill_blank', $item['question_type']);
        $this->assertSame('fraction', $item['fill_blank_answer_format']);
    }

    public function test_word_answer_stays_mcq(): void
    {
        $item = $this->classification->applyMixedClassification([
            'question_text' => 'Who read the most books?',
            'correct_answer' => 'Bhuvan',
            'mcq_options' => [
                ['text' => 'Anya', 'is_correct' => false],
                ['text' => 'Bhuvan', 'is_correct' => true],
            ],
        ]);

        $this->assertSame('mcq', $item['question_type']);
        $this->assertTrue($item['include_in_mcq']);
    }

    public function test_answer_with_unit_becomes_fill_blank_with_bare_number(): void
    {
        $item = $this->classification->applyMixedClassification([
            'question_text' => 'The vertical distance from the bridge to the river bottom is',
            'correct_answer' => '55 m',
            'mcq_options' => [
                ['text' => '55 m', 'is_correct' => true],
                ['text' => '35 m', 'is_correct' => false],
            ],
        ]);

        $this->assertSame('fill_blank', $item['question_type']);
        $this->assertSame('55', $item['fill_blank_correct_answer']);
        $this->assertStringContainsString('____', $item['fill_blank_question_text']);
        $this->assertStringContainsString('metres', $item['fill_blank_question_text']);
    }

    public function test_manual_convert_from_mcq(): void
    {
        $item = $this->classification->convertItemToFillBlank([
            'question_text' => '[(–10) × (+9)] + (–10) is equal to',
            'correct_answer' => '–100',
            'mcq_options' => [
                ['text' => '–100', 'is_correct' => true],
                ['text' => '100', 'is_correct' => false],
            ],
        ]);

        $this->assertSame('fill_blank', $item['question_type']);
    }

    public function test_manual_convert_rejects_word_answer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->classification->convertItemToFillBlank([
            'question_text' => 'Which month had the most sales?',
            'correct_answer' => 'February',
            'mcq_options' => [
                ['text' => 'February', 'is_correct' => true],
            ],
        ]);
    }

    public function test_staging_gemini_parses_figure_status(): void
    {
        $service = app(TextbookChapterStagingGeminiService::class);

        $paste = <<<'TXT'
Question 1
Status: Correct
Figure: OK
Note:

Question 2
Status: Needs Verification
Figure: Missing
Note: Upload bar chart
TXT;

        $parsed = (new \ReflectionMethod($service, 'parseGeminiOutput'))->invoke($service, $paste);

        $this->assertSame('correct', $parsed[1]['status']);
        $this->assertSame('ok', $parsed[1]['figure']);
        $this->assertSame('needs_verification', $parsed[2]['status']);
        $this->assertSame('missing', $parsed[2]['figure']);
    }
}
