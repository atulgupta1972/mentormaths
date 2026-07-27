<?php

namespace Tests\Feature\Console;

use App\Mail\StudentDailyBalanceReminder;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\StudentProgressSummaryService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendDailyStudentBalanceRemindersTest extends TestCase
{
    use RefreshDatabase;

    private function seedEnrollmentWithPendingAssignment(): StudentEnrollment
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $user = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'email' => 'student@example.com',
        ]);

        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Rahul Sharma',
            'email' => 'parent@example.com',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'parent1_email' => 'parent@example.com',
            'notify_parent1_email' => true,
            'school_name' => 'Demo School',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Integers practice',
            'set_code' => 'C7-INT-P1',
            'set_number' => 1,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
        ]);

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $user->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addDay()->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        return $enrollment;
    }

    public function test_balance_reminder_includes_pending_practice(): void
    {
        $enrollment = $this->seedEnrollmentWithPendingAssignment();

        $summary = app(StudentProgressSummaryService::class)->buildBalanceReminder($enrollment, now());

        $this->assertSame(1, $summary['stats']['balance_count']);
        $this->assertSame(1, $summary['stats']['pending_count']);
        $this->assertSame(1, $summary['stats']['practice_count']);
        $this->assertArrayHasKey('pending_days_label', $summary['pending'][0]);
    }

    public function test_command_sends_daily_balance_email_when_work_is_pending(): void
    {
        Mail::fake();

        $this->seedEnrollmentWithPendingAssignment();

        $this->artisan('students:send-daily-balance-reminders')
            ->assertSuccessful();

        Mail::assertSent(StudentDailyBalanceReminder::class, 1);
    }

    public function test_command_skips_students_with_no_balance_work(): void
    {
        Mail::fake();

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $student = Student::query()->create([
            'name' => 'All Done',
            'email' => 'done@example.com',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'parent1_email' => 'done@example.com',
            'notify_parent1_email' => true,
            'school_name' => 'Demo School',
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $this->artisan('students:send-daily-balance-reminders')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }
}
