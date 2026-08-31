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
use App\Services\ClassCoverageService;
use App\Services\ExamPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminClassHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_class_hub_for_class_with_students_and_study_plan(): void
    {
        $this->withoutVite();

        [$admin, $grade] = $this->seedClassSeven();

        $this->actingAs($admin)
            ->get(route('admin.classes.show', $grade->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Classes/Show')
                ->where('gradeLevel.id', $grade->id)
                ->has('examPlanRows', 1)
                ->where('examPlanRows.0.student_name', 'Class Seven Student')
                ->where('examPlanRows.0.progress.sets_done', fn ($value) => $value !== null)
                ->loadDeferredProps('default', fn ($deferred) => $deferred
                    ->has('examPlanProgress')));
    }

    public function test_class_hub_deferred_progress_loads_without_full_page_query(): void
    {
        $this->withoutVite();

        [$admin, $grade] = $this->seedClassSeven();

        $this->actingAs($admin)
            ->get(route('admin.classes.show', $grade->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Classes/Show')
                ->loadDeferredProps('default', fn ($deferred) => $deferred
                    ->has('examPlanProgress')
                    ->missing('examPlanRows')
                    ->missing('gradeLevel')));
    }

    public function test_class_hub_progress_uses_all_assigned_sets_not_study_plan_marks(): void
    {
        $this->seedClassSeven();
        $enrollment = StudentEnrollment::query()->firstOrFail();
        $coverage = app(ClassCoverageService::class);

        $this->assertNotNull($coverage->classHubPerformance($enrollment));
        $this->assertNotNull($coverage->studyPlanPerformance($enrollment));

        StudentChapterCoverage::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->delete();

        $this->assertNull($coverage->studyPlanPerformance($enrollment));
        $this->assertNotNull($coverage->classHubPerformance($enrollment));
    }

    public function test_class_hub_still_lists_students_when_exam_rows_fail(): void
    {
        $this->withoutVite();

        [$admin, $grade] = $this->seedClassSeven();

        $this->partialMock(ExamPlanService::class, function ($mock) {
            $mock->shouldReceive('classHubRows')
                ->andThrow(new \RuntimeException('exam rows unavailable'));
        });

        $this->actingAs($admin)
            ->get(route('admin.classes.show', $grade->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Classes/Show')
                ->has('examPlanRows', 1)
                ->where('examPlanRows.0.student_name', 'Class Seven Student')
                ->where('loadError', fn ($message) => is_string($message) && $message !== ''));
    }

    public function test_class_hub_lists_students_from_every_board_by_default(): void
    {
        $this->withoutVite();

        [$admin, $grade, $cbse] = $this->seedClassSeven();
        $year = AcademicYear::active();
        $icse = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'ICSE Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543211',
            'school_name' => 'Demo',
        ]);
        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $icse->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.classes.show', $grade->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Classes/Show')
                ->has('examPlanRows', 2)
                ->where('stats.students_count', 2)
                ->where('selectedBoardId', null));

        $this->actingAs($admin)
            ->get(route('admin.classes.show', [$grade->id, 'board_id' => $cbse->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Classes/Show')
                ->has('examPlanRows', 1)
                ->where('examPlanRows.0.student_name', 'Class Seven Student'));
    }

    /**
     * @return array{0: User, 1: GradeLevel, 2: Board}
     */
    private function seedClassSeven(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
            'protect_test_attempts' => true,
            'protect_practice_attempts' => true,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
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
            'chapter_number' => 'Ch 1',
            'sort_order' => 1,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Class Seven Student',
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

        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapter->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
        ]);

        return [$admin, $grade, $board];
    }
}
