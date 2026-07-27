<?php

namespace Tests\Feature\Admin;

use App\Mail\StudentDailyBalanceReminder;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\StudentNotificationEmailService;
use App\Services\StudentProgressSummaryService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\ProgressSummaryTable;
use App\Support\WorksheetDeliveryMode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PendingWorkEmailTest extends TestCase
{
    use RefreshDatabase;

    private function seedEnrollmentWithOverdueAssignment(): StudentEnrollment
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
            'email' => 'student.contact@example.com',
            'parent1_name' => 'Parent One',
            'parent1_mobile' => '9876543210',
            'parent1_email' => 'parent@example.com',
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
            'assigned_at' => now()->subDays(5),
            'due_date' => now()->subDays(2)->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        return $enrollment;
    }

    public function test_balance_reminder_recipients_cc_parent_when_on_file(): void
    {
        $enrollment = $this->seedEnrollmentWithOverdueAssignment();

        $recipients = app(StudentNotificationEmailService::class)
            ->balanceReminderRecipients($enrollment->student);

        $this->assertContains('student.contact@example.com', $recipients['to']);
        $this->assertContains('student@example.com', $recipients['to']);
        $this->assertContains('parent@example.com', $recipients['cc']);
    }

    public function test_balance_reminder_includes_pending_days_label(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');

        $enrollment = $this->seedEnrollmentWithOverdueAssignment();

        $summary = app(StudentProgressSummaryService::class)->buildBalanceReminder($enrollment, now());

        $this->assertSame('2 days overdue', $summary['overdue'][0]['pending_days_label']);

        Carbon::setTestNow();
    }

    public function test_pending_days_meta_for_assigned_work(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');

        $meta = ProgressSummaryTable::pendingDaysMeta([
            'assigned_at' => '2026-07-01 10:00:00',
            'is_overdue' => false,
        ], now());

        $this->assertSame('Pending 3 days', $meta['pending_days_label']);

        Carbon::setTestNow();
    }

    public function test_admin_can_send_pending_work_to_all(): void
    {
        Mail::fake();

        $this->seedEnrollmentWithOverdueAssignment();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('admin.notifications.send-pending-work'))
            ->assertRedirect();

        Mail::assertSent(StudentDailyBalanceReminder::class, 1);
    }

    public function test_admin_can_send_pending_work_for_single_student(): void
    {
        Mail::fake();

        $enrollment = $this->seedEnrollmentWithOverdueAssignment();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('admin.students.send-pending-work', $enrollment->student_id))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(StudentDailyBalanceReminder::class, function (StudentDailyBalanceReminder $mail) use ($enrollment) {
            return $mail->student->id === $enrollment->student_id
                && $mail->summary['stats']['balance_count'] === 1;
        });
    }

    public function test_notifications_settings_page_loads_for_admin(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Notifications/Index')
                ->has('mailSettings.mailer')
                ->has('mailSettings.cron_command'));
    }
}
