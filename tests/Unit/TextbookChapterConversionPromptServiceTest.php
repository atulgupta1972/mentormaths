<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Services\TextbookChapterConversionPromptService;
use App\Services\TextbookSetCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextbookChapterConversionPromptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_includes_mcq_reference_and_fill_blank_set_code(): void
    {
        $chapter = $this->seedChapterWithMcqs();

        $service = new TextbookChapterConversionPromptService(new TextbookSetCodeService);
        $payload = $service->payload($chapter);

        $this->assertStringContainsString('mcq_reference.json', $payload['prompt']);
        $this->assertStringContainsString('MUST be a number or a fraction only', $payload['prompt']);
        $this->assertStringContainsString('rewrite the question completely', $payload['prompt']);
        $this->assertStringNotContainsString('short algebra token', $payload['prompt']);
        $this->assertSame('C9-GP-CH08-F1', $payload['fill_blank_set_code']);
        $this->assertSame('C9-GP-CH08-W1', $payload['written_set_code']);
        $this->assertSame(1, $payload['question_count']);

        $reference = json_decode($payload['mcq_reference_json'], true);
        $this->assertSame('175', $reference['questions'][0]['correct_answer']);
        $this->assertSame(['128', '175', '190'], $reference['questions'][0]['options']);
    }

    private function seedChapterWithMcqs(): TextbookChapter
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Data Handling',
            'chapter_number' => 'Ch 3',
            'sort_order' => 3,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        return TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'question_text' => 'What is the total?',
                'correct_answer' => '175',
                'mcq_options' => [
                    ['text' => '128', 'is_correct' => false],
                    ['text' => '175', 'is_correct' => true],
                    ['text' => '190', 'is_correct' => false],
                ],
            ]],
        ]);
    }
}
