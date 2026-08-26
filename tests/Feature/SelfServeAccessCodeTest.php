<?php

namespace Tests\Feature;

use App\Mail\AccessCodeIssued;
use App\Models\AcademicYear;
use App\Models\AccessCode;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SelfServeAccessCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'teacher', 'mentor', 'student'] as $code) {
            Group::query()->firstOrCreate(
                ['code' => $code],
                ['name' => ucfirst($code), 'is_active' => true],
            );
        }
    }

    private function seedYearBoardGrade(): array
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

        return compact('year', 'board', 'grade');
    }

    public function test_mentor_self_serve_issues_tcode_without_approval(): void
    {
        Mail::fake();

        $response = $this->post(route('mentor-access.store'), [
            'class_name' => 'Bright Tuition',
            'teacher_name' => 'Anita Mentor',
            'mobile' => '9876500001',
            'email' => 'anita.mentor@example.com',
        ]);

        $response->assertRedirect(route('mentor-access.thank-you'));

        $user = User::query()->where('email', 'anita.mentor@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->isMentor() || $user->isTeacher());

        $code = AccessCode::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($code);
        $this->assertSame(AccessCode::TYPE_MENTOR, $code->type);
        $this->assertTrue($code->expires_at->equalTo($code->generated_at->copy()->addDays(15)));

        Mail::assertSent(AccessCodeIssued::class, function (AccessCodeIssued $mail) use ($code) {
            return $mail->hasTo('anita.mentor@example.com')
                && $mail->accessCode->is($code)
                && $mail->envelope()->subject === 'Your Mentor Maths tcode + next steps'
                && $mail->content()->view === 'emails.mentor-access-welcome';
        });

        $this->assertTrue(
            $this->post(route('login'), [
                'email' => 'anita.mentor@example.com',
                'password' => $code->code,
            ])->isRedirect()
        );
        $this->assertAuthenticatedAs($user);
    }

    public function test_student_self_serve_issues_tcode_and_approves_immediately(): void
    {
        Mail::fake();
        ['board' => $board, 'grade' => $grade] = $this->seedYearBoardGrade();

        $response = $this->post(route('registration.store'), [
            'student_name' => 'Rahul Sharma',
            'student_mobile' => '9876543211',
            'parent1_name' => 'Mr Sharma',
            'parent1_mobile' => '9876543210',
            'parent1_email' => 'mentor.parent@example.com',
            'school_name' => 'Demo School',
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'email' => 'rahul.login@example.com',
            'notify_parent1_mobile' => true,
            'notify_student_mobile' => true,
            'enrollment_source' => 'individual',
        ]);

        $response->assertRedirect(route('registration.thank-you'));

        $this->assertDatabaseHas('registration_requests', [
            'email' => 'rahul.login@example.com',
            'status' => RegistrationRequest::STATUS_APPROVED,
        ]);

        $user = User::query()->where('email', 'rahul.login@example.com')->first();
        $this->assertNotNull($user);

        $code = AccessCode::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($code);
        $this->assertSame(AccessCode::TYPE_STUDENT, $code->type);

        $this->post(route('login'), [
            'email' => $code->code,
            'password' => '',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_coaching_enrollment_uses_teacher_as_mentor_without_parent1_fields(): void
    {
        Mail::fake();
        ['board' => $board, 'grade' => $grade] = $this->seedYearBoardGrade();

        $this->post(route('mentor-access.store'), [
            'class_name' => 'Sunrise Tuition',
            'teacher_name' => 'Neeru Mentor',
            'mobile' => '9876500091',
            'email' => 'neeru.coach@example.com',
        ])->assertRedirect(route('mentor-access.thank-you'));

        $teacher = \App\Models\CoachingClassTeacher::query()
            ->where('mobile', '9876500091')
            ->first();
        $this->assertNotNull($teacher);

        $response = $this->post(route('registration.store'), [
            'student_name' => 'Akshara Singh',
            'student_mobile' => '9876543222',
            'school_name' => 'Demo School',
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'email' => 'akshara.coach@example.com',
            'notify_student_mobile' => true,
            'enrollment_source' => 'coaching',
            'coaching_class_id' => $teacher->coaching_class_id,
            'coaching_class_teacher_id' => $teacher->id,
        ]);

        $response->assertRedirect(route('registration.thank-you'));

        $this->assertDatabaseHas('students', [
            'name' => 'Akshara Singh',
            'parent1_name' => 'Neeru Mentor',
            'parent1_mobile' => '9876500091',
            'coaching_class_teacher_id' => $teacher->id,
            'enrollment_source' => 'coaching',
        ]);
    }

    public function test_expired_tcode_blocks_login(): void
    {
        Mail::fake();

        $this->post(route('mentor-access.store'), [
            'class_name' => 'Bright Tuition',
            'teacher_name' => 'Anita Mentor',
            'mobile' => '9876500002',
            'email' => 'expired.mentor@example.com',
        ]);

        $user = User::query()->where('email', 'expired.mentor@example.com')->first();
        $code = AccessCode::query()->where('user_id', $user->id)->first();
        $code->update([
            'expires_at' => now()->subDay(),
            'status' => AccessCode::STATUS_EXPIRED,
        ]);

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => 'expired.mentor@example.com',
                'password' => $code->code,
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
