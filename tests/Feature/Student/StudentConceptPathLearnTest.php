<?php

namespace Tests\Feature\Student;

use App\Http\Middleware\EnsureBasicsDrillComplete;
use App\Http\Middleware\EnsureFormulaDrillComplete;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentConceptPathProgress;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ConceptPathStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StudentConceptPathLearnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware([
            EnsureFormulaDrillComplete::class,
            EnsureBasicsDrillComplete::class,
        ]);
    }

    public function test_study_plan_exposes_let_me_learn_concepts_cta(): void
    {
        [$user, $syllabusChapter, $textbookChapter] = $this->seedStudentWithApprovedConcepts();

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Student/SchoolStudyPlan')
                ->where('classCoverage.chapters.0.id', $syllabusChapter->id)
                ->where('classCoverage.chapters.0.concept_learn.textbook_chapter_id', $textbookChapter->id)
                ->where('classCoverage.chapters.0.concept_learn.status', 'not_started')
                ->where(
                    'classCoverage.chapters.0.concept_learn.learn_url',
                    route('student.concept-path.show', $textbookChapter),
                )
            );
    }

    public function test_student_can_learn_concepts_and_progress_is_recorded(): void
    {
        [$user, , $textbookChapter] = $this->seedStudentWithApprovedConcepts();

        $this->actingAs($user)
            ->get(route('student.concept-path.show', $textbookChapter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Student/ConceptPathLearn')
                ->has('path.cards', 2)
                ->where('progress.status', StudentConceptPathProgress::STATUS_IN_PROGRESS)
            );

        $this->assertDatabaseHas('student_concept_path_progress', [
            'user_id' => $user->id,
            'textbook_chapter_id' => $textbookChapter->id,
            'status' => StudentConceptPathProgress::STATUS_IN_PROGRESS,
            'cards_total' => 2,
        ]);

        $this->actingAs($user)
            ->post(route('student.concept-path.record-card', $textbookChapter), [
                'card_index' => 0,
                'card_step' => 1,
                'card_type' => 'teach',
                'action' => 'complete_card',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('student.concept-path.complete', $textbookChapter))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('student_concept_path_progress', [
            'user_id' => $user->id,
            'textbook_chapter_id' => $textbookChapter->id,
            'status' => StudentConceptPathProgress::STATUS_COMPLETED,
            'cards_completed' => 2,
        ]);
    }

    /**
     * @return array{0: User, 1: SyllabusChapter, 2: TextbookChapter}
     */
    private function seedStudentWithApprovedConcepts(): array
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

        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'name' => 'Lines and Angles',
            'chapter_number' => 'Ch 5',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $book = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'code' => 'GP',
            'name' => 'Ganita Prakash',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 5,
            'title' => 'Lines and Angles',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'concept_path_status' => ConceptPathStatus::APPROVED,
            'concept_path_approved_at' => now(),
            'concept_path_items' => [
                'chapter_title' => 'Lines and Angles',
                'cards' => [
                    [
                        'step' => 1,
                        'type' => 'teach',
                        'title' => 'Corresponding angles',
                        'body' => 'Same side of transversal.',
                        'approved' => true,
                    ],
                    [
                        'step' => 2,
                        'type' => 'check',
                        'title' => 'Quick check',
                        'approved' => true,
                        'questions' => [
                            [
                                'question_type' => 'fill_blank',
                                'question' => 'Corresponding angles are ____',
                                'correct_answer' => 'equal',
                                'answer_format' => 'text',
                            ],
                        ],
                    ],
                ],
            ],
            'created_by' => $admin->id,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Learner',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        return [$user, $syllabusChapter, $textbookChapter];
    }
}
