<?php

namespace Tests\Feature\Student;

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

class ClassCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);
    }

    public function test_marking_chapter_under_study_moves_previous_and_earlier_to_studied(): void
    {
        [$user, $enrollment, $chapters] = $this->seedStudentWithChapters(4);

        $this->actingAs($user)
            ->put(route('student.class-coverage.update', $chapters[2]), [
                'status' => 'under_study',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[0]->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);
        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[1]->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);
        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[2]->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
        ]);
        $this->assertDatabaseMissing('student_chapter_coverages', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[3]->id,
        ]);

        $this->actingAs($user)
            ->put(route('student.class-coverage.update', $chapters[3]), [
                'status' => 'under_study',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[2]->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);
        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[3]->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
        ]);

        $this->assertSame(
            1,
            StudentChapterCoverage::query()
                ->where('student_enrollment_id', $enrollment->id)
                ->where('status', StudentChapterCoverage::STATUS_UNDER_STUDY)
                ->count(),
        );
    }

    public function test_student_can_mark_chapter_studied(): void
    {
        [$user, $enrollment, $chapters] = $this->seedStudentWithChapters(2);

        $this->actingAs($user)
            ->put(route('student.class-coverage.update', $chapters[0]), [
                'status' => 'studied',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[0]->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);
    }

    public function test_foreign_syllabus_chapter_is_rejected(): void
    {
        [$user] = $this->seedStudentWithChapters(1);

        $otherYear = AcademicYear::query()->create([
            'name' => 'Other',
            'starts_on' => '2025-03-01',
            'ends_on' => '2026-02-28',
            'is_active' => false,
        ]);
        $otherBoard = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);
        $otherGrade = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $subject = Subject::query()->where('code', 'MATHS')->firstOrFail();

        $otherSyllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $otherYear->id,
            'grade_level_id' => $otherGrade->id,
            'board_id' => $otherBoard->id,
            'subject_id' => $subject->id,
        ]);

        $foreignChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $otherSyllabus->id,
            'name' => 'Foreign',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $this->actingAs($user)
            ->put(route('student.class-coverage.update', $foreignChapter), [
                'status' => 'under_study',
            ])
            ->assertSessionHasErrors('syllabus_chapter_id');
    }

    public function test_school_study_plan_page_includes_class_coverage(): void
    {
        [$user, , $chapters] = $this->seedStudentWithChapters(2);

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/SchoolStudyPlan')
                ->has('classCoverage.chapters', 2)
                ->where('classCoverage.chapters.0.id', $chapters[0]->id)
                ->where('classCoverage.chapters.0.studied', false)
                ->where('classCoverage.chapters.0.under_study', false));
    }

    /**
     * @return array{0: User, 1: StudentEnrollment, 2: list<SyllabusChapter>}
     */
    private function seedStudentWithChapters(int $count): array
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
        for ($i = 1; $i <= $count; $i++) {
            $chapters[] = SyllabusChapter::query()->create([
                'syllabus_version_id' => $syllabus->id,
                'name' => "Chapter {$i}",
                'chapter_number' => $i,
                'sort_order' => $i,
            ]);
        }

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Coverage Student',
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

        return [$user, $enrollment, $chapters];
    }
}
