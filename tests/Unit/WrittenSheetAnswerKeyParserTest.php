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

    public function test_parses_tabular_answer_key_with_tabs(): void
    {
        $text = <<<'TXT'
T712-W — Answer Key
Q.	Question	Answer
1	11 + 12 - (-12 - 11) + 8	54
2	-18 + [12 - (-7 + 5)] - [(-9) - 6]	11
3	25 - [8 - (-6 + 4)] + [-12 - (-5 - 3)]	11
4	-32 - [15 - (-8 - 7)] + [6 - (-4 + 9)]	-61
9	(-144) / [6 x (-4)] + 18 / (-3)	0
18	36 / [(-3) x (-2)] - [(-4) x 5] + 18 / (-3)	20
Note: Work through each question yourself first.
TXT;

        $result = $this->parser->parse($text);

        $this->assertSame(6, $result['parsed_count']);
        $this->assertSame('54', $result['rows'][0]['correct_answer']);
        $this->assertSame('integer', $result['rows'][0]['answer_format']);
        $this->assertSame('11', $result['rows'][1]['correct_answer']);
        $this->assertSame('-61', $result['rows'][3]['correct_answer']);
        $this->assertSame('0', $result['rows'][4]['correct_answer']);
        $this->assertSame('20', $result['rows'][5]['correct_answer']);
    }

    public function test_parses_flattened_whitespace_answer_table(): void
    {
        $text = 'T712-W — Answer Key Class 7 · CBSE · Chapter: Integers · Sheet: T712-W Q. Question Answer '
            .'1 11 + 12 - (-12 - 11) + 8 54 '
            .'2 -18 + [12 - (-7 + 5)] - [(-9) - 6] 11 '
            .'3 25 - [8 - (-6 + 4)] + [-12 - (-5 - 3)] 11 '
            .'4 -32 - [15 - (-8 - 7)] + [6 - (-4 + 9)] -61 '
            .'5 40 - [18 - {(-7) - (-12 + 5)}] + (-9) 13 '
            .'6 (-4) x [7 - (-3 + 5)] + (-6) x 2 -32 '
            .'7 (-3) x [8 - (-2 x 4)] - 5 x (-6) -18 '
            .'8 [(-5) x (-4)] - [3 x {(-6) + 2}] + (-8) x 2 16 '
            .'9 (-144) / [6 x (-4)] + 18 / (-3) 0 '
            .'10 (-96) / [(-8) + 4] - [36 / (-6)] 30 '
            .'11 120 / [(-5) x (-3)] + (-72) / 8 - 4 -5 '
            .'12 (-180) / [9 - (-6)] + [(-84) / (-7)] - 5 -5 '
            .'13 (-240) / [(-4) x 5] - [(-96) / 12] + 7 27 '
            .'14 (-24) / 6 x (-3) + 5 - [8 - (-2)] 7 '
            .'15 [(-6) x 8] / (-4) + [(-15) / 3] x (-2) 22 '
            .'16 (-5) x [18 / (-3) + 4] - [(-8) x 2] 26 '
            .'17 [(-72) / 8] x [(-5) + 2] + 6 x (-4) 3 '
            .'18 36 / [(-3) x (-2)] - [(-4) x 5] + 18 / (-3) 20 '
            .'Note: Work through each question yourself first.';

        $result = $this->parser->parse($text);

        $this->assertSame(18, $result['parsed_count']);
        $this->assertSame('54', $result['rows'][0]['correct_answer']);
        $this->assertSame('-61', $result['rows'][3]['correct_answer']);
        $this->assertSame('-32', $result['rows'][5]['correct_answer']);
        $this->assertSame('-5', $result['rows'][10]['correct_answer']);
        $this->assertSame('20', $result['rows'][17]['correct_answer']);
    }

    public function test_parses_real_t712_answer_key_pdf_when_available(): void
    {
        $path = 'C:/Users/Atul.Gupta/scmapp_imports/tests/T712-W_Answer_Key.pdf';

        if (! is_file($path)) {
            $this->markTestSkipped('Local T712 answer key PDF not present.');
        }

        $text = (new \Smalot\PdfParser\Parser)->parseFile($path)->getText();
        $result = $this->parser->parse($text);

        $this->assertSame(18, $result['parsed_count']);
        $this->assertSame('54', $result['rows'][0]['correct_answer']);
        $this->assertSame('11', $result['rows'][1]['correct_answer']);
        $this->assertSame('-61', $result['rows'][3]['correct_answer']);
        $this->assertSame('20', $result['rows'][17]['correct_answer']);
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
