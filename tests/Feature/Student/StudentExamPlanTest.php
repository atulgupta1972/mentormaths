<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ExamPlan;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentExamPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_create_exam_plan(): void
    {
        [$user, $chapter] = $this->seedStudentUser();

        $response = $this->actingAs($user)->post(route('student.exam-plans.store'), [
            'exam_date' => now()->addWeeks(2)->toDateString(),
            'title' => 'Unit test 1',
            'exam_type' => ExamPlan::TYPE_UNIT_TEST,
            'notes' => 'Algebra focus',
            'chapter_selections' => [
                ['syllabus_chapter_id' => $chapter->id, 'syllabus_topic_ids' => null],
            ],
        ]);

        $response->assertRedirect(route('student.exams.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('exam_plans', [
            'title' => 'Unit test 1',
            'exam_type' => ExamPlan::TYPE_UNIT_TEST,
        ]);
    }

    public function test_student_can_view_exams_page(): void
    {
        $this->withoutVite();

        [$user, , $plan] = $this->seedStudentPlan();

        $this->actingAs($user)
            ->get(route('student.exams.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Exams/Index')
                ->has('examPlans.upcoming', 1)
                ->where('examPlans.upcoming.0.title', 'Unit test 1')
                ->has('allPlans', 1)
                ->has('syllabusChapters')
                ->has('examTypeOptions'));
    }

    public function test_student_can_update_exam_plan(): void
    {
        [$user, $chapter, $plan] = $this->seedStudentPlan();

        $response = $this->actingAs($user)->put(route('student.exam-plans.update', $plan), [
            'exam_date' => $plan->exam_date->toDateString(),
            'title' => 'Updated title',
            'exam_type' => $plan->exam_type,
            'notes' => 'Updated notes',
            'obtained_marks' => 38,
            'total_marks' => 50,
            'chapter_selections' => [
                ['syllabus_chapter_id' => $chapter->id, 'syllabus_topic_ids' => null],
            ],
        ]);

        $response->assertRedirect(route('student.exams.index'));
        $response->assertSessionHas('success');

        $plan->refresh();
        $this->assertSame('Updated title', $plan->title);
        $this->assertSame(38, $plan->obtained_marks);
        $this->assertSame(50, $plan->total_marks);
    }

    public function test_student_cannot_update_another_students_exam_plan(): void
    {
        [, , $plan] = $this->seedStudentPlan();

        $otherUser = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $otherStudent = Student::query()->create([
            'user_id' => $otherUser->id,
            'name' => 'Other Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543211',
            'school_name' => 'School',
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $otherStudent->id,
            'academic_year_id' => $plan->enrollment->academic_year_id,
            'board_id' => $plan->enrollment->board_id,
            'grade_level_id' => $plan->enrollment->grade_level_id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $this->actingAs($otherUser)
            ->put(route('student.exam-plans.update', $plan), [
                'exam_date' => $plan->exam_date->toDateString(),
                'title' => 'Hack',
                'exam_type' => $plan->exam_type,
                'chapter_selections' => [
                    ['syllabus_chapter_id' => $plan->chapters()->first()->id, 'syllabus_topic_ids' => null],
                ],
            ])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: SyllabusChapter}
     */
    private function seedStudentUser(): array
    {
        [$user, $chapter, $enrollment] = $this->seedStudentContext();

        return [$user, $chapter];
    }

    /**
     * @return array{0: User, 1: SyllabusChapter, 2: ExamPlan}
     */
    private function seedStudentPlan(): array
    {
        [$user, $chapter, $enrollment] = $this->seedStudentContext();

        $plan = ExamPlan::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'exam_date' => now()->addWeek()->toDateString(),
            'title' => 'Unit test 1',
            'exam_type' => ExamPlan::TYPE_UNIT_TEST,
            'created_by' => $user->id,
            'status' => ExamPlan::STATUS_PLANNED,
        ]);

        $plan->chapters()->attach($chapter->id);

        return [$user, $chapter, $plan];
    }

    /**
     * @return array{0: User, 1: SyllabusChapter, 2: StudentEnrollment}
     */
    private function seedStudentContext(): array
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
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

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

        return [$user, $chapter, $enrollment];
    }
}
