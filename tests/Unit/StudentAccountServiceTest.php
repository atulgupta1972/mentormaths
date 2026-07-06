<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\StudentAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivate_marks_enrollment_inactive_and_disables_login(): void
    {
        $year = AcademicYear::query()->create([
            'name' => '2025-26',
            'starts_on' => '2025-04-01',
            'ends_on' => '2026-03-31',
            'is_active' => true,
        ]);

        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT, 'is_active' => true]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Duplicate Student',
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

        $service = app(StudentAccountService::class);

        $this->assertTrue($service->isActive($student));

        $service->deactivate($student);

        $this->assertFalse($service->isActive($student->fresh()));
        $this->assertSame(StudentEnrollment::STATUS_INACTIVE, $enrollment->fresh()->status);
        $this->assertFalse($user->fresh()->isActiveAccount());
        $this->assertNull($student->fresh()->currentEnrollment());
    }

    public function test_activate_restores_enrollment_and_login(): void
    {
        $year = AcademicYear::query()->create([
            'name' => '2025-26',
            'starts_on' => '2025-04-01',
            'ends_on' => '2026-03-31',
            'is_active' => true,
        ]);

        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT, 'is_active' => false]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Duplicate Student',
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
            'status' => StudentEnrollment::STATUS_INACTIVE,
        ]);

        $service = app(StudentAccountService::class);

        $service->activate($student);

        $this->assertTrue($service->isActive($student->fresh()));
        $this->assertTrue($user->fresh()->isActiveAccount());
        $this->assertNotNull($student->fresh()->currentEnrollment());
    }

    public function test_delete_removes_student_and_login(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT, 'is_active' => true]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Duplicate Student',
            'student_mobile' => '9876543210',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $service = app(StudentAccountService::class);
        $service->delete($student);

        $this->assertDatabaseMissing('students', ['id' => $student->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
