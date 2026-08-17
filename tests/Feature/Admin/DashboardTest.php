<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\QuestionResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_loads_with_an_active_student(): void
    {
        $this->withoutVite();

        [$admin] = $this->seedAdminDashboard();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isAdmin', true)
                ->where('stats.students_count', 1)
                ->where('students.0.syllabus_chapters', []));
    }

    public function test_admin_can_load_one_student_dashboard_detail(): void
    {
        $this->withoutVite();

        [$admin, $enrollment] = $this->seedAdminDashboard();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.student', $enrollment->student_id))
            ->assertOk()
            ->assertJsonPath('student_id', $enrollment->student_id)
            ->assertJsonPath('help_requests_count', 0);
    }

    public function test_admin_dashboard_still_loads_when_help_requests_fail(): void
    {
        $this->withoutVite();

        [$admin] = $this->seedAdminDashboard();

        $this->mock(QuestionResolutionService::class, function ($mock) {
            $mock->shouldReceive('pendingForStudentIds')
                ->andThrow(new \RuntimeException('help requests unavailable'));
        });

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isAdmin', true)
                ->where('stats.students_count', 1)
                ->where('helpRequests', []));
    }

    /**
     * @return array{0: User, 1: StudentEnrollment}
     */
    private function seedAdminDashboard(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Dashboard Student',
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

        return [$admin, $enrollment];
    }
}
