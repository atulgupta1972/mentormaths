<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ExamPlan;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentChapterTerm;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Services\StudentLearningBlocker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class LearningBlockerPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('exam_gates.first_term_marks_required', true);
        Config::set('exam_gates.chapter_terms_required', true);
    }

    public function test_missing_marks_redirect_asks_the_student_to_enter_them(): void
    {
        [$user] = $this->seedStudent(withMarks: false);

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.exams.index'))
            ->assertSessionHas(StudentLearningBlocker::PROMPT_FLASH, true);

        $this->actingAs($user)
            ->get(route('student.exams.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('learningBlocker.key', 'first_term_marks')
                ->where('learningBlocker.blocks_assign', true)
                ->where('learningBlocker.action_url', route('student.exams.index')));
    }

    public function test_unmapped_terms_redirect_asks_the_student_to_tag_chapters(): void
    {
        [$user] = $this->seedStudent(withMarks: true);

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.school-study-plan.show'))
            ->assertSessionHas(StudentLearningBlocker::PROMPT_FLASH, true);

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('learningBlocker.key', 'chapter_terms')
                ->where('learningBlocker.blocks_assign', true));
    }

    public function test_formula_drill_is_the_prompt_after_the_plan_is_ticked(): void
    {
        [$user, $chapters, $enrollment] = $this->seedStudent(withMarks: true);
        $this->mapBothTerms($enrollment, $chapters);
        $user->forceFill(['created_at' => now()->subDays(3)])->save();

        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[0]->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
            'marked_under_study_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.formula-drill.show'))
            ->assertSessionHas(StudentLearningBlocker::PROMPT_FLASH, true);

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('learningBlocker.key', 'formula_drill')
                ->where('learningBlocker.blocks_assign', false)
                ->where('learningBlocker.action_url', route('student.formula-drill.show')));
    }

    public function test_unticked_study_plan_does_not_block_concepts(): void
    {
        [$user, $chapters, $enrollment] = $this->seedStudent(withMarks: true);
        $this->mapBothTerms($enrollment, $chapters);

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('learningBlocker', null));
    }

    /**
     * @param  list<SyllabusChapter>  $chapters
     */
    private function mapBothTerms(StudentEnrollment $enrollment, array $chapters): void
    {
        foreach ($chapters as $index => $chapter) {
            StudentChapterTerm::query()->create([
                'student_enrollment_id' => $enrollment->id,
                'syllabus_chapter_id' => $chapter->id,
                'term' => $index === 0
                    ? StudentChapterTerm::TERM_FIRST
                    : StudentChapterTerm::TERM_SECOND,
            ]);
        }
    }

    /**
     * @return array{0: User, 1: list<SyllabusChapter>, 2: StudentEnrollment}
     */
    private function seedStudent(bool $withMarks): array
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

        $chapters = [];
        foreach (['Integers', 'Fractions', 'Triangles'] as $i => $name) {
            $chapters[] = SyllabusChapter::query()->create([
                'syllabus_version_id' => $syllabus->id,
                'name' => $name,
                'chapter_number' => (string) ($i + 1),
                'sort_order' => $i + 1,
            ]);
        }

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        if ($withMarks) {
            ExamPlan::query()->create([
                'student_enrollment_id' => $enrollment->id,
                'exam_date' => now()->subDays(2)->toDateString(),
                'title' => 'Half yearly',
                'exam_type' => ExamPlan::TYPE_HALF_YEARLY,
                'obtained_marks' => 60,
                'total_marks' => 100,
                'marks_entered_at' => now(),
                'created_by' => $user->id,
                'status' => ExamPlan::STATUS_COMPLETED,
            ]);
        }

        return [$user, $chapters, $enrollment];
    }
}
