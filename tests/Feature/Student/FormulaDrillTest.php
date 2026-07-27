<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\FormulaDrillSession;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormulaDrillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{student: Student, user: User, formulaQuestion: Question}
     */
    private function seedStudentWithCompletedChapter(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 7',
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

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Drill Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Integers practice',
            'set_code' => 'C7-INT-P1',
            'set_number' => 1,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
        ]);

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $user->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addDay()->toDateString(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'bank_purpose' => QuestionBankPurpose::FORMULA,
            'question_text' => 'Additive inverse of −8 is:',
            'explanation' => 'It is 8',
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '−8',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '8',
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        return [
            'student' => $student,
            'user' => $user,
            'formulaQuestion' => $question,
        ];
    }

    public function test_dashboard_redirects_to_formula_drill_before_completion(): void
    {
        ['student' => $student, 'user' => $user] = $this->seedStudentWithCompletedChapter();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.formula-drill.show'));
    }

    public function test_student_can_complete_formula_drill_and_access_dashboard(): void
    {
        ['student' => $student, 'user' => $user, 'formulaQuestion' => $question] = $this->seedStudentWithCompletedChapter();

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Student/FormulaDrill/Show'));

        $session = FormulaDrillSession::query()->where('student_id', $student->id)->firstOrFail();
        $item = $session->items()->firstOrFail();
        $correctOptionId = $question->options()->where('is_correct', true)->value('id');

        $this->actingAs($user)
            ->postJson(route('student.formula-drill.answer', $item), [
                'option_id' => $correctOptionId,
            ])
            ->assertOk()
            ->assertJsonPath('session_complete', true);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_admin_bypasses_formula_drill_gate(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
