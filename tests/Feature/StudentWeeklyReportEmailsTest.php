<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use App\Services\StudentNotificationEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentWeeklyReportEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_save_weekly_report_parent_emails(): void
    {
        [$user, $student] = $this->seedStudentUser();

        $response = $this->actingAs($user)->patch(route('profile.weekly-report-emails.update'), [
            'weekly_report_emails' => 'parent1@example.com, parent2@example.com',
        ]);

        $response->assertRedirect();
        $student->refresh();

        $this->assertSame('parent1@example.com', $student->parent1_email);
        $this->assertSame('parent2@example.com', $student->parent2_email);
        $this->assertTrue($student->notify_parent1_email);
        $this->assertTrue($student->notify_parent2_email);

        $addresses = app(StudentNotificationEmailService::class)->emailAddressesForStudent($student);
        $this->assertContains('parent1@example.com', $addresses);
        $this->assertContains('parent2@example.com', $addresses);
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function seedStudentUser(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $user = User::factory()->create(['email' => 'student@example.com']);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Student',
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

        return [$user, $student];
    }
}
