<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Services\McqToFillBlankService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McqToFillBlankServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_converts_whole_number_mcq_to_fill_blank(): void
    {
        $topic = $this->seedTopic();
        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is 7 × 8?',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '54',
            'is_correct' => false,
            'sort_order' => 1,
        ]);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '56',
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        $service = app(McqToFillBlankService::class);
        $inspect = $service->inspect($question->fresh('options'));
        $this->assertTrue($inspect['convertible']);
        $this->assertSame('56', $inspect['answer']);
        $this->assertSame(QuestionBlankAnswer::FORMAT_INTEGER, $inspect['answer_format']);

        $converted = $service->convert($question->fresh(['options', 'blankAnswer']));

        $this->assertTrue($converted->isFillInBlank());
        $this->assertStringContainsString('____', $converted->question_text);
        $this->assertSame('56', $converted->blankAnswer->correct_answer);
        $this->assertSame(0, $converted->options()->count());
    }

    public function test_rejects_word_answer_mcq(): void
    {
        $topic = $this->seedTopic();
        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Which are polynomials?',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => 'Only (i) and (iii)',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $inspect = app(McqToFillBlankService::class)->inspect($question->fresh('options'));
        $this->assertFalse($inspect['convertible']);
    }

    private function seedTopic(): SyllabusTopic
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        return SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);
    }
}
