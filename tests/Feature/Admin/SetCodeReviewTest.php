<?php

namespace Tests\Feature\Admin;

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
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetCodeReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_shows_attached_mcq_questions(): void
    {
        $this->withoutVite();

        [$topic, $admin] = $this->seedTopicWithAdmin();
        $worksheet = $this->makeSet($topic, 'S721');
        $question = $this->makeMcq($topic, 'What is 7 + 2?');
        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $this->actingAs($admin)
            ->get(route('admin.questions.set-code', ['code' => 'S721']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetCodeReview')
                ->where('result.set_code', 'S721')
                ->where('result.is_fill_in_blank', false)
                ->where('result.questions_count', 1)
                ->where('result.questions.0.question_text', 'What is 7 + 2?')
            );
    }

    public function test_empty_mcq_set_is_not_labelled_fill_in_blank(): void
    {
        $this->withoutVite();

        [$topic, $admin] = $this->seedTopicWithAdmin();
        $this->makeSet($topic, 'S721');

        $this->actingAs($admin)
            ->get(route('admin.questions.set-code', ['code' => 'S721']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetCodeReview')
                ->where('result.set_code', 'S721')
                ->where('result.is_fill_in_blank', false)
                ->where('result.questions_count', 0)
                ->where('result.questions_restored', false)
            );
    }

    public function test_empty_published_set_restores_unpackaged_topic_questions(): void
    {
        $this->withoutVite();

        [$topic, $admin] = $this->seedTopicWithAdmin();
        $worksheet = $this->makeSet($topic, 'S721');
        $question = $this->makeMcq($topic, 'Integers: −3 + 5 = ?');

        $this->assertSame(0, $worksheet->questions()->count());

        $this->actingAs($admin)
            ->get(route('admin.questions.set-code', ['code' => 'S721']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetCodeReview')
                ->where('result.set_code', 'S721')
                ->where('result.is_fill_in_blank', false)
                ->where('result.questions_count', 1)
                ->where('result.questions_restored', true)
                ->where('result.questions.0.question_text', 'Integers: −3 + 5 = ?')
            );

        $this->assertTrue($worksheet->fresh()->questions()->whereKey($question->id)->exists());
    }

    public function test_empty_fill_blank_set_restores_only_fill_blank_questions(): void
    {
        $this->withoutVite();

        [$topic, $admin] = $this->seedTopicWithAdmin();
        $worksheet = $this->makeSet($topic, 'SF721');
        $this->makeMcq($topic, 'MCQ that belongs on S721');
        $fillBlank = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => '−3 + 5 = ____',
            'type' => Question::TYPE_FILL_IN_BLANK,
            'source' => Question::SOURCE_AI,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
        ]);
        QuestionBlankAnswer::query()->create([
            'question_id' => $fillBlank->id,
            'answer_format' => 'integer',
            'correct_answer' => '2',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.questions.set-code', ['code' => 'SF721']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetCodeReview')
                ->where('result.set_code', 'SF721')
                ->where('result.is_fill_in_blank', true)
                ->where('result.questions_count', 1)
                ->where('result.questions.0.question_text', '−3 + 5 = ____')
            );

        $this->assertTrue($worksheet->fresh()->questions()->whereKey($fillBlank->id)->exists());
        $this->assertSame(1, $worksheet->fresh()->questions()->count());
    }

    public function test_lookup_points_to_fill_blank_sibling_set(): void
    {
        $this->withoutVite();

        [$topic, $admin] = $this->seedTopicWithAdmin();
        $mcqSet = $this->makeSet($topic, 'S721');
        $fillSet = $this->makeSet($topic, 'SF721', 2);
        $fillBlank = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => '2 + 2 = ____',
            'type' => Question::TYPE_FILL_IN_BLANK,
            'source' => Question::SOURCE_MANUAL,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
        ]);
        QuestionBlankAnswer::query()->create([
            'question_id' => $fillBlank->id,
            'answer_format' => 'integer',
            'correct_answer' => '4',
        ]);
        $fillSet->questions()->attach($fillBlank->id, ['sort_order' => 1]);
        $mcq = $this->makeMcq($topic, 'Pick 4');
        $mcqSet->questions()->attach($mcq->id, ['sort_order' => 1]);

        $this->actingAs($admin)
            ->get(route('admin.questions.set-code', ['code' => 'S721']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetCodeReview')
                ->where('result.sibling_set.set_code', 'SF721')
                ->where('result.sibling_set.questions_count', 1)
                ->where('result.sibling_set.is_fill_in_blank', true)
            );
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
            'chapter_number' => 2,
            'sort_order' => 2,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition of integers',
            'sort_order' => 1,
        ]);

        return [$topic, $admin];
    }

    private function makeSet(SyllabusTopic $topic, string $code, int $setNumber = 1): Worksheet
    {
        return Worksheet::query()->create([
            'title' => $code,
            'set_number' => $setNumber,
            'set_code' => $code,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);
    }

    private function makeMcq(SyllabusTopic $topic, string $text): Question
    {
        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => $text,
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_AI,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
        ]);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '2',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        return $question;
    }
}
