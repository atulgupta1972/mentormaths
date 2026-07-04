<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Services\QuestionMethodHintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionMethodHintServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fill_for_topic_generates_hints_for_questions_missing_them(): void
    {
        $topic = $this->createTopic();

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => '(-4) × (-3) × (-2) = ______',
            'method_hint' => null,
            'explanation' => 'Working. Answer key: c.',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is the area of a circle?',
            'method_hint' => 'Use πr².',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        $service = app(QuestionMethodHintService::class);
        $result = $service->fillForTopic($topic);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['unresolved']);
        $this->assertSame(1, $result['explanations_cleaned']);

        $generated = Question::query()->where('syllabus_topic_id', $topic->id)
            ->where('question_text', 'like', '(-4)%')
            ->first();

        $this->assertNotNull($generated->method_hint);
        $this->assertStringNotContainsString('-24', $generated->method_hint);
        $this->assertSame('Working', $generated->explanation);
    }

    public function test_fill_for_topic_skips_existing_hints_unless_overwrite(): void
    {
        $topic = $this->createTopic();

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => '(-2) × (-5) = ______',
            'method_hint' => 'Custom teacher hint.',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        $service = app(QuestionMethodHintService::class);
        $result = $service->fillForTopic($topic, overwrite: false);

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);

        $result = $service->fillForTopic($topic, overwrite: true);

        $this->assertSame(1, $result['updated']);
        $this->assertStringContainsString('negative × negative', strtolower(
            Question::query()->where('syllabus_topic_id', $topic->id)->value('method_hint')
        ));
    }

    public function test_stats_for_topic_counts_hints(): void
    {
        $topic = $this->createTopic();

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Q1',
            'method_hint' => 'A hint.',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);
        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Q2',
            'method_hint' => null,
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        $stats = app(QuestionMethodHintService::class)->statsForTopic($topic);

        $this->assertSame(2, $stats['total']);
        $this->assertSame(1, $stats['with_hint']);
        $this->assertSame(1, $stats['missing_hint']);
    }

    private function createTopic(): SyllabusTopic
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths']);

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

        return SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Multiplication',
            'sort_order' => 1,
        ]);
    }
}
