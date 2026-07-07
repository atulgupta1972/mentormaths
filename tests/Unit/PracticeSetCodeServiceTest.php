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
use App\Models\Worksheet;
use App\Services\PracticeSetCodeService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeSetCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fill_in_blank_chapter_practice_uses_sf_prefix(): void
    {
        [$chapter, $topic] = $this->seedChapterWithTopic();
        $service = app(PracticeSetCodeService::class);

        Worksheet::query()->create([
            'title' => 'MCQ topic set',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $this->assertSame('SF711', $service->generateChapterPractice($chapter, PracticeSetTier::STARTER, true));
    }

    public function test_mcq_and_fill_in_blank_sequences_are_independent(): void
    {
        [$chapter, $topic] = $this->seedChapterWithTopic();
        $service = app(PracticeSetCodeService::class);

        Worksheet::query()->create([
            'title' => 'MCQ topic set',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        Worksheet::query()->create([
            'title' => 'Fill blank topic set',
            'set_number' => 2,
            'set_code' => 'SF711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $this->assertSame('S712', $service->generate($topic, PracticeSetTier::STARTER, false));
        $this->assertSame('SF712', $service->generate($topic, PracticeSetTier::STARTER, true));
    }

    public function test_chapter_test_codes_ignore_practice_sets(): void
    {
        [$chapter, $topic] = $this->seedChapterWithTopic();
        $service = app(PracticeSetCodeService::class);

        Worksheet::query()->create([
            'title' => 'Fill blank set',
            'set_number' => 1,
            'set_code' => 'SF711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $this->assertSame('T711', $service->generateChapterTest($chapter));
    }

    public function test_question_ids_are_all_fill_in_blank_helper(): void
    {
        [$chapter, $topic] = $this->seedChapterWithTopic();

        $fillBlank = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => '2 + 2 = ____',
            'source' => Question::SOURCE_MANUAL,
        ]);

        $mcq = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'question_text' => 'Pick one',
            'source' => Question::SOURCE_MANUAL,
        ]);

        $this->assertTrue(Question::idsAreAllFillInBlank([$fillBlank->id]));
        $this->assertFalse(Question::idsAreAllFillInBlank([$fillBlank->id, $mcq->id]));
    }

    /**
     * @return array{0: SyllabusChapter, 1: SyllabusTopic}
     */
    private function seedChapterWithTopic(): array
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
            'chapter_number' => 1,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Introduction',
            'sort_order' => 1,
        ]);

        return [$chapter, $topic];
    }
}
