<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
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
use App\Support\WorksheetDeliveryMode;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionBankBrowseRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_cannot_see_practice_set_questions_or_answers(): void
    {
        [$student, $worksheet, $secret] = $this->seedPracticeSetWithQuestion();

        $this->actingAs($student)
            ->get(route('admin.questions.sets.show', $worksheet))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetQuestions')
                ->where('canViewQuestions', false)
                ->where('questions', [])
                ->missing('questions.0.question_text')
                ->missing('questions.0.correct_answer')
            )
            ->assertDontSee($secret);
    }

    public function test_admin_can_still_see_practice_set_questions(): void
    {
        [, $worksheet, $secret] = $this->seedPracticeSetWithQuestion();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.questions.sets.show', $worksheet))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetQuestions')
                ->where('canViewQuestions', true)
                ->has('questions', 1)
                ->where('questions.0.question_text', $secret)
            );
    }

    public function test_student_cannot_see_formula_set_cards_or_answers(): void
    {
        [$student, $worksheet, $secret] = $this->seedFormulaSetWithQuestion();

        $this->actingAs($student)
            ->get(route('admin.formula-bank.sets.show', $worksheet))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/FormulaBank/SetShow')
                ->where('canViewQuestions', false)
                ->where('set.questions', [])
                ->where('set.questions_count', 1)
            )
            ->assertDontSee($secret);
    }

    public function test_student_topic_bank_hides_question_text(): void
    {
        [$student, $topic, $secret] = $this->seedTopicBankQuestion();

        $this->actingAs($student)
            ->get(route('admin.questions.topics.show', $topic))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/TopicQuestions')
                ->where('canViewQuestions', false)
                ->where('questions.data.0.question_text', null)
            )
            ->assertDontSee($secret);
    }

    /**
     * @return array{0: User, 1: Worksheet, 2: string}
     */
    private function seedPracticeSetWithQuestion(): array
    {
        [$topic, $student] = $this->seedTopicAndStudent();
        $secret = 'SECRET_PRACTICE_Q_TEXT_XYZ';

        $worksheet = Worksheet::query()->create([
            'title' => 'Practice set 1',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $student->id,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => $secret,
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_AI,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => 'SECRET_CORRECT_OPTION',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        return [$student, $worksheet, $secret];
    }

    /**
     * @return array{0: User, 1: Worksheet, 2: string}
     */
    private function seedFormulaSetWithQuestion(): array
    {
        [$topic, $student] = $this->seedTopicAndStudent();
        $secret = 'SECRET_FORMULA_Q_TEXT_XYZ';

        $worksheet = Worksheet::query()->create([
            'title' => 'Formula set 1',
            'set_number' => 1,
            'set_code' => 'F711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::FORMULA,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $student->id,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => $secret,
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_AI,
            'bank_purpose' => QuestionBankPurpose::FORMULA,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => 'SECRET_FORMULA_OPTION',
            'is_correct' => true,
            'sort_order' => 1,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        return [$student, $worksheet, $secret];
    }

    /**
     * @return array{0: User, 1: SyllabusTopic, 2: string}
     */
    private function seedTopicBankQuestion(): array
    {
        [$topic, $student] = $this->seedTopicAndStudent();
        $secret = 'SECRET_TOPIC_BANK_Q_TEXT_XYZ';

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => $secret,
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_AI,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
        ]);

        return [$student, $topic, $secret];
    }

    /**
     * @return array{0: SyllabusTopic, 1: User}
     */
    private function seedTopicAndStudent(): array
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
            'name' => 'Algebra',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Identities',
            'sort_order' => 1,
        ]);
        $student = User::factory()->create(['role' => User::ROLE_STUDENT]);

        return [$topic, $student];
    }
}
