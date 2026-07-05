<?php

namespace Tests\Unit;

use App\Mail\AssignmentAssigned;
use App\Models\Student;
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
}
