<?php

namespace Tests\Unit;

use App\Services\McqImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McqImportServiceTest extends TestCase
{
    use RefreshDatabase;
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

    public function test_chapter_prompt_includes_topic_plan(): void
    {
        [$chapter, $topics] = $this->seedChapterWithTopics();

        $prompt = app(McqImportService::class)->cursorPromptForChapter($chapter, [
            [
                'topic_id' => $topics[0]->id,
                'topic_name' => $topics[0]->name,
                'easy' => 5,
                'medium' => 1,
                'hard' => 2,
            ],
        ]);

        $this->assertStringContainsString('Rational Numbers: Easy 5, Medium 1, Hard 2', $prompt);
        $this->assertStringContainsString('"topic"', $prompt);
    }

    /**
     * @return array{0: \App\Models\SyllabusChapter, 1: list<\App\Models\SyllabusTopic>}
     */
    private function seedChapterWithTopics(): array
    {
        $year = \App\Models\AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $grade = \App\Models\GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $board = \App\Models\Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $subject = \App\Models\Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths']);

        $syllabus = \App\Models\SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = \App\Models\SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Number Systems',
            'sort_order' => 1,
        ]);

        $topics = [
            \App\Models\SyllabusTopic::query()->create([
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Rational Numbers',
                'sort_order' => 1,
            ]),
        ];

        return [$chapter, $topics];
    }
}
