<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Support\QuestionBankPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormulaResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_student_can_browse_formula_chapters(): void
    {
        [$user, $chapter, $secret] = $this->seedStudentWithFormulaCard();

        $this->actingAs($user)
            ->get(route('student.resources.formulas.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Resources/Formulas/Index')
                ->has('chapters', 1)
                ->where('chapters.0.id', $chapter->id)
                ->where('chapters.0.formulas_count', 1)
                ->where('total_formulas', 1));
    }

    public function test_student_can_open_chapter_formula_cards_with_answers(): void
    {
        [$user, $chapter, $secret] = $this->seedStudentWithFormulaCard();

        $this->actingAs($user)
            ->get(route('student.resources.formulas.chapter', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Resources/Formulas/Chapter')
                ->where('chapter.id', $chapter->id)
                ->where('formulas_count', 1)
                ->has('topics', 1)
                ->where('topics.0.cards.0.correct_answer', 'a² − b² = (a − b)(a + b)')
                ->where('topics.0.cards.0.question_text', $secret));
    }

    public function test_student_cannot_open_other_class_chapter(): void
    {
        [$user] = $this->seedStudentWithFormulaCard();

        $otherYear = AcademicYear::query()->first();
        $otherBoard = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);
        $otherGrade = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $subject = Subject::query()->where('code', 'MATHS')->first();

        $otherSyllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $otherYear->id,
            'grade_level_id' => $otherGrade->id,
            'board_id' => $otherBoard->id,
            'subject_id' => $subject->id,
        ]);

        $foreignChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $otherSyllabus->id,
            'chapter_number' => '1',
            'name' => 'Foreign Chapter',
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('student.resources.formulas.chapter', $foreignChapter))
            ->assertForbidden();
    }

    public function test_class_coverage_no_longer_lists_formula_column(): void
    {
        [$user] = $this->seedStudentWithFormulaCard();

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/SchoolStudyPlan')
                ->where(
                    'classCoverage.availability_columns',
                    fn ($columns) => collect($columns)->pluck('key')->doesntContain('formula')
                        && collect($columns)->pluck('key')->contains('practice'),
                ));
    }

    /**
     * @return array{0: User, 1: SyllabusChapter, 2: string}
     */
    private function seedStudentWithFormulaCard(): array
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
            'chapter_number' => '1',
            'name' => 'Integers',
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Algebra identities',
            'sort_order' => 1,
        ]);

        $secret = 'Which identity expands a² − b²?';

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'question_text' => $secret,
            'bank_purpose' => QuestionBankPurpose::FORMULA,
            'explanation' => 'Difference of squares',
            'source' => Question::SOURCE_MANUAL,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => 'a² − b² = (a − b)(a + b)',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => 'a² + b² = (a + b)²',
            'is_correct' => false,
            'sort_order' => 2,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Formula Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        return [$user, $chapter, $secret];
    }
}
