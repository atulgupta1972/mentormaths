<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolStudyPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_admin_can_view_and_update_student_school_study_plan(): void
    {
        [$admin, $student, $grade, $chapters] = $this->seedAdminAndStudent();

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.school-study-plan.index', ['student_id' => $student->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SchoolStudyPlan/Index')
                ->where('selectedStudent.id', $student->id)
                ->has('classCoverage.chapters', 3));

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->put(route('admin.school-study-plan.update', [$student, $chapters[1]]), [
                'status' => 'under_study',
            ])
            ->assertRedirect();

        $enrollmentId = $student->enrollments()->first()->id;

        $this->assertDatabaseMissing('student_chapter_coverages', [
            'student_enrollment_id' => $enrollmentId,
            'syllabus_chapter_id' => $chapters[0]->id,
        ]);
        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollmentId,
            'syllabus_chapter_id' => $chapters[1]->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->put(route('admin.school-study-plan.update', [$student, $chapters[1]]), [
                'status' => 'none',
            ])
            ->assertRedirect(route('admin.school-study-plan.index', ['student_id' => $student->id]));

        $this->assertDatabaseMissing('student_chapter_coverages', [
            'student_enrollment_id' => $enrollmentId,
            'syllabus_chapter_id' => $chapters[1]->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Student, 2: GradeLevel, 3: list<SyllabusChapter>}
     */
    private function seedAdminAndStudent(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapters = [];
        for ($i = 1; $i <= 3; $i++) {
            $chapters[] = SyllabusChapter::query()->create([
                'syllabus_version_id' => $syllabus->id,
                'name' => "Chapter {$i}",
                'chapter_number' => $i,
                'sort_order' => $i,
            ]);
        }

        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'name' => 'Plan Student',
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

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$admin, $student, $grade, $chapters];
    }
}
