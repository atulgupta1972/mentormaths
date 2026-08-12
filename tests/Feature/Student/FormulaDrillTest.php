<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\FormulaDrillSession;
use App\Models\FormulaQuestionStat;
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
     * @return array{student: Student, user: User, formulaQuestion: Question, topic: SyllabusTopic}
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

        $question = $this->createFormulaQuestion($topic, 'Additive inverse of −8 is:', '8');

        return [
            'student' => $student,
            'user' => $user,
            'formulaQuestion' => $question,
            'topic' => $topic,
        ];
    }

    private function createFormulaQuestion(SyllabusTopic $topic, string $text, string $correctAnswer): Question
    {
        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'bank_purpose' => QuestionBankPurpose::FORMULA,
            'question_text' => $text,
            'explanation' => 'Answer is '.$correctAnswer,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => 'wrong',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => $correctAnswer,
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        return $question;
    }

    /**
     * @return array{
     *     student: Student,
     *     user: User,
     *     class6Question: Question,
     *     class7Question: Question,
     *     class7Topic: SyllabusTopic,
     * }
     */
    private function seedClass7StudentWithPreviousGradeFormulas(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $grade6 = GradeLevel::query()->create(['name' => 'Class 6', 'sort_order' => 6, 'is_active' => true]);
        $grade7 = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $syllabus6 = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade6->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 6',
        ]);

        $chapter6 = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus6->id,
            'chapter_number' => 1,
            'name' => 'Fractions',
            'sort_order' => 1,
        ]);

        $topic6 = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter6->id,
            'name' => 'Basics',
            'sort_order' => 1,
        ]);

        $syllabus7 = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade7->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 7',
        ]);

        $chapter7 = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus7->id,
            'chapter_number' => 1,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);

        $topic7 = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter7->id,
            'name' => 'Introduction',
            'sort_order' => 1,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Class 7 Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade7->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Integers practice',
            'set_code' => 'C7-INT-P1',
            'set_number' => 1,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic7->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
        ]);

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $user->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addDay()->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $class6Question = $this->createFormulaQuestion($topic6, 'Half of 10 is:', '5');
        $class7Question = $this->createFormulaQuestion($topic7, 'Additive inverse of −8 is:', '8');

        return [
            'student' => $student,
            'user' => $user,
            'class6Question' => $class6Question,
            'class7Question' => $class7Question,
            'class7Topic' => $topic7,
        ];
    }

    /**
     * @return array{
     *     student: Student,
     *     user: User,
     *     class8Question: Question,
     *     class9Question: Question
     * }
     */
    private function seedClass9StudentWithPreviousGradeFormulas(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $grade8 = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $grade9 = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);

        $syllabus8 = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade8->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 8',
        ]);

        $chapter8 = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus8->id,
            'chapter_number' => 1,
            'name' => 'Rational Numbers',
            'sort_order' => 1,
        ]);

        $topic8 = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter8->id,
            'name' => 'Properties',
            'sort_order' => 1,
        ]);

        $syllabus9 = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade9->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 9',
        ]);

        $chapter9 = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus9->id,
            'chapter_number' => 1,
            'name' => 'Number Systems',
            'sort_order' => 1,
        ]);

        $topic9 = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter9->id,
            'name' => 'Irrational numbers',
            'sort_order' => 1,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Class 9 Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade9->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Number systems practice',
            'set_code' => 'C9-NS-P1',
            'set_number' => 1,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic9->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
        ]);

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $user->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addDay()->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $class8Question = $this->createFormulaQuestion($topic8, '(a + b)² expands to:', 'a² + 2ab + b²');
        $class9Question = $this->createFormulaQuestion($topic9, '√2 is:', 'irrational');

        return [
            'student' => $student,
            'user' => $user,
            'class8Question' => $class8Question,
            'class9Question' => $class9Question,
        ];
    }

    /**
     * @return array{student: Student, user: User, formulaQuestion: Question}
     */
    private function seedStudentWithAssignedChapter(): array
    {
        $data = $this->seedStudentWithCompletedChapter();

        SetAssignment::query()
            ->where('student_enrollment_id', $data['student']->currentEnrollment()?->id)
            ->update(['status' => SetAssignment::STATUS_ASSIGNED]);

        return $data;
    }

    public function test_dashboard_redirects_to_formula_drill_before_completion(): void
    {
        ['student' => $student, 'user' => $user] = $this->seedStudentWithCompletedChapter();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.formula-drill.show'));
    }

    public function test_formula_drill_uses_assigned_chapters_when_none_completed_yet(): void
    {
        ['student' => $student, 'user' => $user, 'formulaQuestion' => $question] = $this->seedStudentWithAssignedChapter();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.formula-drill.show'));

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertOk();

        $this->assertDatabaseHas('formula_drill_sessions', [
            'student_id' => $student->id,
            'status' => FormulaDrillSession::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_skipped_session_retries_when_pool_becomes_available(): void
    {
        ['student' => $student, 'user' => $user] = $this->seedStudentWithAssignedChapter();

        FormulaDrillSession::query()->create([
            'student_id' => $student->id,
            'drill_date' => now(config('formula_drill.timezone', 'Asia/Kolkata'))->startOfDay(),
            'status' => FormulaDrillSession::STATUS_SKIPPED,
            'questions_total' => 0,
            'questions_completed' => 0,
            'pool_size' => 0,
            'completed_at' => now(),
        ]);

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
            ->assertRedirect(route('student.basics-drill.show'));
    }

    public function test_admin_bypasses_formula_drill_gate(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_pool_includes_all_previous_grade_formulas(): void
    {
        [
            'student' => $student,
            'class6Question' => $class6Question,
            'class7Question' => $class7Question,
        ] = $this->seedClass7StudentWithPreviousGradeFormulas();

        $poolIds = app(\App\Services\FormulaDrillPoolService::class)->poolQuestionIds($student);

        $this->assertContains($class6Question->id, $poolIds);
        $this->assertContains($class7Question->id, $poolIds);
    }

    public function test_class_9_student_gets_all_class_8_formulas_in_drill_pool(): void
    {
        [
            'student' => $student,
            'class8Question' => $class8Question,
            'class9Question' => $class9Question,
        ] = $this->seedClass9StudentWithPreviousGradeFormulas();

        $poolService = app(\App\Services\FormulaDrillPoolService::class);
        $poolIds = $poolService->poolQuestionIds($student);
        $breakdown = $poolService->poolBreakdown($student);

        $this->assertContains($class8Question->id, $poolIds);
        $this->assertContains($class9Question->id, $poolIds);
        $this->assertSame('Class 8', $breakdown['previous_grade_name']);
        $this->assertSame(1, $breakdown['previous_grade_count']);
        $this->assertSame(1, $breakdown['current_grade_count']);
    }

    public function test_formula_drill_does_not_repeat_until_pool_exhausted(): void
    {
        [
            'student' => $student,
            'user' => $user,
            'class6Question' => $class6Question,
            'class7Question' => $class7Question,
            'class7Topic' => $class7Topic,
        ] = $this->seedClass7StudentWithPreviousGradeFormulas();

        $extraQuestion = $this->createFormulaQuestion($class7Topic, 'Sum of −3 and 5 is:', '2');

        FormulaQuestionStat::query()->create([
            'student_id' => $student->id,
            'question_id' => $class6Question->id,
            'total_failures' => 0,
            'times_shown' => 1,
            'times_correct' => 1,
            'times_exhausted' => 0,
            'needs_review' => false,
            'last_shown_date' => now()->subDay()->toDateString(),
            'last_correct_at' => now()->subDay(),
        ]);

        config(['formula_drill.daily_question_count' => 2]);

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertOk();

        $session = FormulaDrillSession::query()->where('student_id', $student->id)->firstOrFail();
        $selectedIds = $session->items()->pluck('question_id')->all();

        $this->assertNotContains($class6Question->id, $selectedIds);
        $this->assertContains($class7Question->id, $selectedIds);
        $this->assertContains($extraQuestion->id, $selectedIds);
    }
}
