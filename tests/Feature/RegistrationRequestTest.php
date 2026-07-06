<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\RegistrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationRequestTest extends TestCase
{
    use RefreshDatabase;

    private function seedRegistrationPrerequisites(): array
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

    private function validPayload(int $boardId, int $gradeId, string $email = 'parent@example.com'): array
    {
        return [
            'student_name' => 'Rahul Sharma',
            'student_mobile' => '9876543211',
            'parent1_name' => 'Mr Sharma',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo School',
            'board_id' => $boardId,
            'grade_level_id' => $gradeId,
            'email' => $email,
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'notify_parent1_mobile' => true,
        ];
    }

    public function test_registration_form_can_be_rendered_when_year_is_active(): void
    {
        $this->seedRegistrationPrerequisites();

        $this->get(route('registration.create'))
            ->assertOk();
    }

    public function test_user_can_submit_registration_with_chosen_login_credentials(): void
    {
        ['board' => $board, 'grade' => $grade] = $this->seedRegistrationPrerequisites();

        $response = $this->post(route('registration.store'), $this->validPayload($board->id, $grade->id));

        $response->assertRedirect(route('registration.thank-you'));

        $this->assertDatabaseHas('registration_requests', [
            'email' => 'parent@example.com',
            'student_name' => 'Rahul Sharma',
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        $request = RegistrationRequest::query()->first();
        $this->assertNotNull($request->password);
    }

    public function test_duplicate_email_in_users_is_rejected(): void
    {
        ['board' => $board, 'grade' => $grade] = $this->seedRegistrationPrerequisites();

        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->from(route('registration.create'))
            ->post(route('registration.store'), $this->validPayload($board->id, $grade->id, 'taken@example.com'));

        $response
            ->assertRedirect(route('registration.create'))
            ->assertSessionHasErrors('email');
    }

    public function test_duplicate_pending_registration_email_is_rejected(): void
    {
        ['year' => $year, 'board' => $board, 'grade' => $grade] = $this->seedRegistrationPrerequisites();

        RegistrationRequest::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'student_name' => 'Existing',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
            'email' => 'pending@example.com',
            'password' => bcrypt('password'),
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        $response = $this->from(route('registration.create'))
            ->post(route('registration.store'), $this->validPayload($board->id, $grade->id, 'pending@example.com'));

        $response
            ->assertRedirect(route('registration.create'))
            ->assertSessionHasErrors('email');
    }

    public function test_duplicate_name_and_mobile_in_students_is_rejected(): void
    {
        ['year' => $year, 'board' => $board, 'grade' => $grade] = $this->seedRegistrationPrerequisites();

        \App\Models\Student::query()->create([
            'name' => 'Saanvi Dahiya',
            'student_mobile' => '9711011125',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9711011125',
            'school_name' => 'School',
        ]);

        $response = $this->from(route('registration.create'))
            ->post(route('registration.store'), [
                ...$this->validPayload($board->id, $grade->id, 'new@example.com'),
                'student_name' => 'Saanvi Dahiya',
                'student_mobile' => '9711011125',
            ]);

        $response
            ->assertRedirect(route('registration.create'))
            ->assertSessionHasErrors('student_mobile');
    }

    public function test_duplicate_name_and_mobile_in_pending_requests_is_rejected(): void
    {
        ['year' => $year, 'board' => $board, 'grade' => $grade] = $this->seedRegistrationPrerequisites();

        RegistrationRequest::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'student_name' => 'Saanvi Dahiya',
            'student_mobile' => '9711011125',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9711011125',
            'school_name' => 'School',
            'email' => 'pending@example.com',
            'password' => bcrypt('password'),
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        $response = $this->from(route('registration.create'))
            ->post(route('registration.store'), [
                ...$this->validPayload($board->id, $grade->id, 'other@example.com'),
                'student_name' => 'Saanvi Dahiya',
                'student_mobile' => '9711011125',
            ]);

        $response
            ->assertRedirect(route('registration.create'))
            ->assertSessionHasErrors('student_mobile');
    }
}
