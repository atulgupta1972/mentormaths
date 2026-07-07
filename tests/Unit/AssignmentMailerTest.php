<?php

namespace Tests\Unit;

use App\Mail\AssignmentAssigned;
use App\Mail\AssignmentCompleted;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\AssignmentMailer;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AssignmentMailerTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_assigned_emails_student_with_admin_cc(): void
    {
        Mail::fake();

        config(['mail.registration_notify' => 'admin@mentormaths.in']);

        $student = Student::query()->create([
            'name' => 'Parth Gupta',
            'email' => 'parent@example.com',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter set 1',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $result = AssignmentMailer::sendAssigned($student, $worksheet, '2026-07-12', 'Finish by Friday');

        $this->assertTrue($result['sent']);
        $this->assertSame('parent@example.com', $result['email']);

        Mail::assertSent(AssignmentAssigned::class, fn (AssignmentAssigned $mail) => $mail->hasTo('parent@example.com'));
        Mail::assertSentCount(1);
    }

    public function test_send_assigned_skips_when_no_email_on_file(): void
    {
        Mail::fake();

        $student = Student::query()->create([
            'name' => 'No Email Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter set 1',
            'set_number' => 1,
            'set_code' => 'S712',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $result = AssignmentMailer::sendAssigned($student, $worksheet, '2026-07-12');

        $this->assertFalse($result['sent']);
        $this->assertSame('no_email', $result['error']);
        Mail::assertNothingSent();
    }

    public function test_resolve_student_email_falls_back_to_user_login_email(): void
    {
        $user = User::factory()->create([
            'email' => 'student.login@example.com',
        ]);

        $student = Student::query()->create([
            'name' => 'Login Email Student',
            'user_id' => $user->id,
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $this->assertSame('student.login@example.com', AssignmentMailer::resolveStudentEmail($student));
    }

    public function test_send_completed_emails_admin_even_without_student_email(): void
    {
        Mail::fake();

        config(['mail.registration_notify' => 'admin@mentormaths.in']);

        $attempt = $this->seedSubmittedAttempt();

        $result = AssignmentMailer::sendCompleted($attempt);

        $this->assertTrue($result['sent']);
        $this->assertSame('admin@mentormaths.in', $result['email']);

        Mail::assertSent(AssignmentCompleted::class, function (AssignmentCompleted $mail) {
            return $mail->hasTo('admin@mentormaths.in')
                && $mail->summary['attempt_number'] === 1
                && $mail->summary['score_label'] === '3/5';
        });
        Mail::assertSentCount(1);
    }

    public function test_send_completed_skips_when_no_admin_email_configured(): void
    {
        Mail::fake();

        config(['mail.registration_notify' => null]);
        User::query()->delete();

        $attempt = $this->seedSubmittedAttempt();

        $result = AssignmentMailer::sendCompleted($attempt);

        $this->assertFalse($result['sent']);
        $this->assertSame('no_admin_email', $result['error']);
        Mail::assertNothingSent();
    }

    private function seedSubmittedAttempt(): SetAttempt
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create([
            'code' => 'CBSE',
            'name' => 'CBSE',
            'is_active' => true,
        ]);

        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $subject = Subject::query()->create([
            'code' => 'MATHS',
            'name' => 'Mathematics',
        ]);

        $student = Student::query()->create([
            'name' => 'Parth Gupta',
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

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter set 1',
            'set_number' => 1,
            'set_code' => 'S852',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        return SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'score' => 3,
            'max_score' => 5,
            'time_seconds' => 420,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);
    }
}
