<?php

namespace Tests\Unit;

use App\Services\McqImportService;
use Tests\TestCase;

class McqImportServiceTest extends TestCase
{
    public function test_parse_json_accepts_hint_and_camel_case_fields(): void
    {
        $json = <<<'JSON'
{
  "questions": [
    {
      "question": "Which is irrational?",
      "options": [
        { "key": "A", "text": "4" },
        { "key": "B", "text": "22/7" },
        { "key": "C", "text": "√11" },
        { "key": "D", "text": "0.5" }
      ],
      "correctAnswer": "C",
      "hint": "Check whether each number can be written as p/q.",
      "explanation": "√11 is irrational.",
      "difficulty": "easy"
    }
  ]
}
JSON;

        $rows = app(McqImportService::class)->parseJson($json);

        $this->assertCount(1, $rows);
        $this->assertSame('Check whether each number can be written as p/q.', $rows[0]['method_hint']);
        $this->assertTrue(collect($rows[0]['options'])->contains(fn ($opt) => $opt['option_text'] === '√11' && $opt['is_correct']));
    }
}
