<?php

namespace Tests\Unit;

use App\Services\WrittenSheetAnswerKeyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WrittenSheetAnswerKeyParserTest extends TestCase
{
    private WrittenSheetAnswerKeyParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new WrittenSheetAnswerKeyParser;
    }

    public function test_parses_line_numbered_answers(): void
    {
        $text = <<<'TXT'
Answer Sheet
1. 42
2. 3/4
3. 90°
TXT;

        $result = $this->parser->parse($text);

        $this->assertSame(3, $result['parsed_count']);
        $this->assertSame('42', $result['rows'][0]['correct_answer']);
        $this->assertSame('integer', $result['rows'][0]['answer_format']);
        $this->assertSame('3/4', $result['rows'][1]['correct_answer']);
        $this->assertSame('fraction', $result['rows'][1]['answer_format']);
        $this->assertSame('90°', $result['rows'][2]['correct_answer']);
        $this->assertSame('text', $result['rows'][2]['answer_format']);
    }

    public function test_parses_q_prefixed_and_answer_key_block(): void
    {
        $text = 'Answer key: 1. a 2. b 3. c Q4: 12 Q5) x = 7';

        $result = $this->parser->parse($text);

        $this->assertSame(5, $result['parsed_count']);
        $this->assertSame('a', $result['rows'][0]['correct_answer']);
        $this->assertSame('b', $result['rows'][1]['correct_answer']);
        $this->assertSame('12', $result['rows'][3]['correct_answer']);
        $this->assertSame('x = 7', $result['rows'][4]['correct_answer']);
    }

    public function test_parses_full_answer_key_blocks_with_correct_answer_label(): void
    {
        $text = <<<'TXT'
1. [Easy] In the figure, lines l and m are parallel and t is a transversal. Which pair of angles is a pair of corresponding angles? Correct Answer: ∠1 and ∠5 Explanation: ∠1 and ∠5 occupy the same relative position at the two intersection points.
2. [Medium] Find x when 2x + 3 = 11. Correct Answer: 4 Explanation: Subtract 3 then divide by 2.
TXT;

        $result = $this->parser->parse($text);

        $this->assertSame(2, $result['parsed_count']);
        $this->assertSame('∠1 and ∠5', $result['rows'][0]['correct_answer']);
        $this->assertSame('text', $result['rows'][0]['answer_format']);
        $this->assertStringContainsString('relative position', (string) $result['rows'][0]['method_hint']);
        $this->assertSame('4', $result['rows'][1]['correct_answer']);
        $this->assertSame('integer', $result['rows'][1]['answer_format']);
    }

    public function test_warns_when_answer_count_differs_from_worksheet_estimate(): void
    {
        $result = $this->parser->parseWithExpectedCount("1. 5\n2. 6", 4);

        $this->assertSame(2, $result['parsed_count']);
        $this->assertNotEmpty($result['warnings']);
    }

    #[DataProvider('worksheetQuestionCountProvider')]
    public function test_estimates_question_count_from_worksheet(string $text, ?int $expected): void
    {
        $this->assertSame($expected, $this->parser->estimateQuestionCountFromWorksheet($text));
    }

    /**
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function worksheetQuestionCountProvider(): array
    {
        return [
            'numbered body' => ["1. First sum\n2. Second sum\n3. Third sum", 3],
            'stops before answer key' => ["1. Sum\n2. Sum\nAnswer key\n1. 5\n2. 6", 2],
            'empty' => ['No numbers here', null],
        ];
    }
}
