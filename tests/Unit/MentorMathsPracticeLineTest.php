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
use App\Services\FillBlankConversionService;
use App\Services\TextbookChapterConversionPromptService;
use App\Services\TextbookChapterPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentorMathsPracticeLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_mentormaths_prompt_requires_transform_rules(): void
    {
        $chapter = $this->seedChapter(practiceLine: Textbook::PRACTICE_LINE_MENTORMATHS);

        $payload = app(TextbookChapterConversionPromptService::class)->payload($chapter);

        $this->assertTrue($payload['is_mentormaths']);
        $this->assertTrue($payload['transform_required']);
        $this->assertStringContainsString('TRANSFORM RULES', $payload['prompt']);
        $this->assertStringContainsString('Change every significant number', $payload['prompt']);
        $this->assertStringContainsString('no MCQ set', $payload['prompt']);
    }

    public function test_standard_prompt_still_converts_from_mcq(): void
    {
        $chapter = $this->seedChapter(practiceLine: Textbook::PRACTICE_LINE_STANDARD);

        $payload = app(TextbookChapterConversionPromptService::class)->payload($chapter);

        $this->assertFalse($payload['is_mentormaths']);
        $this->assertStringContainsString('mcq_reference.json', $payload['prompt']);
        $this->assertStringContainsString('rewrite the question completely', $payload['prompt']);
    }

    public function test_apply_rejects_near_copy_on_mentormaths_line(): void
    {
        $chapter = $this->seedChapter(practiceLine: Textbook::PRACTICE_LINE_MENTORMATHS);

        $json = json_encode([
            'questions' => [[
                'source_index' => 1,
                'question' => 'What is the total of 67, 55, 18 and 35? The answer is ____.',
                'answer_format' => 'integer',
                'correct_answer' => '175',
                'explanation' => '67+55+18+35 = 175.',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/too close|Numbers are unchanged|publisher/i');

        app(FillBlankConversionService::class)->applyGeminiJsonToChapter($chapter, $json);
    }

    public function test_apply_accepts_rewritten_mentormaths_blank(): void
    {
        $chapter = $this->seedChapter(practiceLine: Textbook::PRACTICE_LINE_MENTORMATHS);

        $json = json_encode([
            'questions' => [[
                'source_index' => 1,
                'topic' => 'Mean',
                'question' => 'A batsman scored 72, 48, 21 and 39 runs. The mean score is ____.',
                'answer_format' => 'integer',
                'correct_answer' => '45',
                'explanation' => '72+48+21+39 = 180. Mean = 180÷4 = 45.',
            ]],
        ], JSON_THROW_ON_ERROR);

        $result = app(FillBlankConversionService::class)->applyGeminiJsonToChapter($chapter, $json);

        $this->assertSame(1, $result['convertible_count']);
        $chapter->refresh();
        $item = $chapter->extraction_items[0];
        $this->assertTrue($item['fill_blank_transformed']);
        $this->assertSame('45', $item['fill_blank_correct_answer']);
    }

    public function test_mcq_publish_blocked_for_mentormaths(): void
    {
        $chapter = $this->seedChapter(practiceLine: Textbook::PRACTICE_LINE_MENTORMATHS);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fill-in-blanks only/i');

        app(TextbookChapterPublishService::class)->publish(
            $chapter,
            $chapter->extraction_items,
            $admin,
        );
    }

    public function test_fill_blank_publish_marks_mentormaths_chapter_published(): void
    {
        $chapter = $this->seedChapter(practiceLine: Textbook::PRACTICE_LINE_MENTORMATHS);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $json = json_encode([
            'questions' => [[
                'source_index' => 1,
                'topic' => 'Mean',
                'question' => 'Four scores are 72, 48, 21 and 39. Their mean is ____.',
                'answer_format' => 'integer',
                'correct_answer' => '45',
                'explanation' => 'Total 180. Mean 45.',
            ]],
        ], JSON_THROW_ON_ERROR);

        app(FillBlankConversionService::class)->applyGeminiJsonToChapter($chapter, $json);

        $published = app(TextbookChapterPublishService::class)
            ->publishFillBlankAndWritten($chapter->fresh(), $admin);

        $this->assertSame(TextbookChapter::STATUS_PUBLISHED, $published->status);
        $this->assertNotEmpty($published->fillBlankWorksheetIds());
        $this->assertSame([], $published->mcqWorksheetIds());
    }

    private function seedChapter(string $practiceLine): TextbookChapter
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
            'name' => $practiceLine === Textbook::PRACTICE_LINE_MENTORMATHS ? 'MentorMaths 1' : 'Ganita Prakash',
            'code' => $practiceLine === Textbook::PRACTICE_LINE_MENTORMATHS ? 'MM1' : 'GP',
            'practice_line' => $practiceLine,
            'source_ref' => $practiceLine === Textbook::PRACTICE_LINE_MENTORMATHS ? 'RDS-C9' : null,
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
                'question_text' => 'What is the total of 67, 55, 18 and 35?',
                'correct_answer' => '175',
                'topic' => 'Mean',
                'mcq_options' => [
                    ['text' => '128', 'is_correct' => false],
                    ['text' => '175', 'is_correct' => true],
                    ['text' => '190', 'is_correct' => false],
                ],
            ]],
        ]);
    }
}
