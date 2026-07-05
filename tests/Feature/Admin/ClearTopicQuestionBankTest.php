<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearTopicQuestionBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_clear_unpackaged_topic_question_bank(): void
    {
        [$topic, $admin] = $this->seedTopicWithAdmin();

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Sample question',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.questions.topics.clear-bank', $topic))
            ->assertRedirect(route('admin.questions.chapters.show', $topic->syllabus_chapter_id));

        $this->assertSame(0, Question::query()->where('syllabus_topic_id', $topic->id)->count());
    }

    public function test_cannot_clear_topic_bank_when_practice_set_exists(): void
    {
        [$topic, $admin] = $this->seedTopicWithAdmin();

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Sample question',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        $set = Worksheet::query()->create([
            'title' => 'Starter set',
            'set_number' => 1,
            'set_code' => 'S931',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);
        $set->questions()->attach($question->id, ['sort_order' => 1]);

        $this->actingAs($admin)
            ->from(route('admin.questions.topics.show', $topic))
            ->delete(route('admin.questions.topics.clear-bank', $topic))
            ->assertRedirect(route('admin.questions.topics.show', $topic));

        $this->assertSame(1, Question::query()->where('syllabus_topic_id', $topic->id)->count());
    }

    /**
     * @return array{0: SyllabusTopic, 1: User}
     */
    private function seedTopicWithAdmin(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
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
            'name' => 'Numbers',
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Rational Numbers',
            'sort_order' => 1,
        ]);

        return [$topic, $admin];
    }
}
