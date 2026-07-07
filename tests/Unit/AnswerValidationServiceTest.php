<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Support\AnswerValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnswerValidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnswerValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AnswerValidationService::class);
    }

    public function test_integer_answer_matches(): void
    {
        $question = $this->fillBlankQuestion(QuestionBlankAnswer::FORMAT_INTEGER, '-4');

        $this->assertTrue($this->service->isCorrect($question, '-4'));
        $this->assertTrue($this->service->isCorrect($question, ' -4 '));
        $this->assertFalse($this->service->isCorrect($question, '4'));
        $this->assertFalse($this->service->isCorrect($question, '-4.0'));
    }

    public function test_decimal_answer_matches_with_places(): void
    {
        $question = $this->fillBlankQuestion(QuestionBlankAnswer::FORMAT_DECIMAL, '3.50', 2);

        $this->assertTrue($this->service->isCorrect($question, '3.5'));
        $this->assertTrue($this->service->isCorrect($question, '3.50'));
        $this->assertFalse($this->service->isCorrect($question, '3.6'));
    }

    public function test_fraction_answer_matches_equivalent_forms(): void
    {
        $question = $this->fillBlankQuestion(QuestionBlankAnswer::FORMAT_FRACTION, '3/4');

        $this->assertTrue($this->service->isCorrect($question, '3/4'));
        $this->assertTrue($this->service->isCorrect($question, '6/8'));
        $this->assertFalse($this->service->isCorrect($question, '1 1/2'));
    }

    public function test_mixed_fraction_answer_matches(): void
    {
        $question = $this->fillBlankQuestion(QuestionBlankAnswer::FORMAT_FRACTION, '1 1/2');

        $this->assertTrue($this->service->isCorrect($question, '1 1/2'));
        $this->assertTrue($this->service->isCorrect($question, '3/2'));
    }

    /**
     * @return Question
     */
    private function fillBlankQuestion(string $format, string $correctAnswer, ?int $decimalPlaces = null): Question
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
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => 'Sample ____',
            'source' => Question::SOURCE_MANUAL,
        ]);

        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'answer_format' => $format,
            'correct_answer' => $correctAnswer,
            'decimal_places' => $decimalPlaces,
        ]);

        return $question->fresh('blankAnswer');
    }
}
