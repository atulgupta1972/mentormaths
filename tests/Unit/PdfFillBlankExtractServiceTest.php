<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Services\PdfFillBlankExtractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfFillBlankExtractServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_splits_numbered_questions_under_section_headings(): void
    {
        $text = <<<'TXT'
CHAPTER 13 WORKSHEET
Two Variables, One Line
A. Linear Equations in Two Variables
1. Write a linear equation in two variables.
2. Rewrite 5y - 3x = 12 in standard form.
B. Solutions as Ordered Pairs
1. Check whether (3, 2) is a solution of 4x - 5y = 2.
2. Find any two solutions of 5x - 2y = 10.
TXT;

        $questions = app(PdfFillBlankExtractService::class)->splitSourceQuestions($text);

        $this->assertCount(4, $questions);
        $this->assertSame('A.1', $questions[0]['label']);
        $this->assertSame('Linear Equations in Two Variables', $questions[0]['section']);
        $this->assertSame('B.2', $questions[3]['label']);
        $this->assertStringContainsString('5x - 2y = 10', $questions[3]['text']);
    }

    public function test_prompt_prefers_fill_in_the_blank_and_keeps_every_source_question(): void
    {
        $chapter = $this->chapter();
        $text = <<<'TXT'
A. Slope
1. Find the slope of the line through A(1, 2) and B(5, 10).
2. Draw the graph of y = 2x and explain what you notice.
TXT;

        $prompt = app(PdfFillBlankExtractService::class)->cursorPrompt($chapter, $text);

        $this->assertStringContainsString('fill-in-the-blank', strtolower($prompt));
        $this->assertStringContainsString('Return exactly 2 JSON items', $prompt);
        $this->assertStringContainsString('type "mcq" ONLY when', $prompt);
        $this->assertStringContainsString('A.1 [Slope]', $prompt);
        $this->assertStringContainsString('Angles on parallel lines', $prompt);
        $this->assertStringContainsString('A(1, 2) and B(5, 10)', $prompt);
    }

    private function chapter(): SyllabusChapter
    {
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths'])->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Two Variables, One Line',
            'chapter_number' => 'p2-ch5',
            'sort_order' => 5,
        ]);
        SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Angles on parallel lines',
            'sort_order' => 1,
        ]);

        return $chapter->fresh(['topics', 'syllabusVersion.board', 'syllabusVersion.gradeLevel', 'syllabusVersion.academicYear']);
    }
}
