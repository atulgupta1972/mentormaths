<?php

namespace Tests\Feature;

use App\Mail\TeacherRegistrationCounterOffer;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\TeacherRegistrationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TeacherRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{board: Board, grades: \Illuminate\Support\Collection<int, GradeLevel>}
     */
    private function seedPrerequisites(): array
    {
        $board = Board::query()->create([
            'code' => 'CBSE',
            'name' => 'CBSE',
            'is_active' => true,
        ]);

        $grades = collect(range(5, 12))->map(fn (int $sort) => GradeLevel::query()->create([
            'name' => "Class {$sort}",
            'sort_order' => $sort,
            'is_active' => true,
        ]));

        return compact('board', 'grades');
    }

    /**
     * @param  list<int>  $contentGradeIds
     * @param  list<int>  $teachingGradeIds
     * @return array<string, mixed>
     */
    private function validPayload(
        int $boardId,
        array $contentGradeIds = [],
        array $teachingGradeIds = [],
        string $email = 'teacher@example.com',
        bool $content = true,
        bool $doubts = false,
    ): array {
        $payload = [
            'name' => 'Priya Verma',
            'email' => $email,
            'mobile' => '9876543210',
            'gender' => 'female',
            'date_of_birth' => '1990-05-15',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
            'years_of_experience' => 5,
            'board_ids' => [$boardId],
            'interested_in_content_creation' => $content,
            'content_grade_level_ids' => $content ? $contentGradeIds : [],
            'creates_mcq' => true,
            'proposed_rate_per_set_inr' => $content ? 500 : null,
            'interested_in_doubt_solving' => $doubts,
            'teaching_grade_level_ids' => $doubts ? $teachingGradeIds : [],
            'agreed_to_terms' => true,
        ];

        if ($doubts) {
            $payload['doubt_sessions_per_week'] = 2;
            $payload['doubt_hours_per_week'] = 2;
            $payload['proposed_hourly_rate_inr'] = 800;
            $payload['expected_start_date'] = now()->addWeek()->toDateString();
            $payload['preferred_days'] = ['mon', 'wed'];
            $payload['preferred_time_slot'] = 'Weekday evenings 6–8 PM IST';
            $payload['agreed_to_mentoring_program'] = true;
        }

        return $payload;
    }

    public function test_teacher_registration_form_can_be_rendered(): void
    {
        $this->withoutVite();
        $this->seedPrerequisites();

        $this->get(route('teacher-registration.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('TeacherRegistration/Create'));
    }

    public function test_teacher_can_submit_content_only_application(): void
    {
        Mail::fake();

        ['board' => $board, 'grades' => $grades] = $this->seedPrerequisites();

        $response = $this->post(
            route('teacher-registration.store'),
            $this->validPayload($board->id, [$grades[0]->id]),
        );

        $response->assertRedirect(route('teacher-registration.thank-you'));

        $this->assertDatabaseHas('teacher_registration_requests', [
            'email' => 'teacher@example.com',
            'status' => TeacherRegistrationRequest::STATUS_PENDING,
            'interested_in_content_creation' => true,
            'interested_in_doubt_solving' => false,
            'proposed_rate_per_set_inr' => 500,
        ]);
    }

    public function test_admin_counter_offer_and_teacher_acceptance_flow(): void
    {
        Mail::fake();

        ['board' => $board, 'grades' => $grades] = $this->seedPrerequisites();
        $gradeIds = $grades->take(2)->pluck('id')->all();

        $this->post(
            route('teacher-registration.store'),
            $this->validPayload($board->id, $gradeIds, $gradeIds, 'doubts@example.com', true, true),
        );

        $application = TeacherRegistrationRequest::query()->where('email', 'doubts@example.com')->firstOrFail();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('admin.teacher-registrations.counter-offer', $application), [
                'counter_hourly_rate_inr' => 650,
                'counter_offer_message' => 'We can offer ₹650/hour for evening slots.',
            ])
            ->assertRedirect();

        $application->refresh();
        $this->assertSame(TeacherRegistrationRequest::STATUS_COUNTER_OFFERED, $application->status);
        $this->assertNotNull($application->counter_offer_token);

        Mail::assertSent(TeacherRegistrationCounterOffer::class);

        $token = $application->counter_offer_token;

        $this->post(route('teacher-registration.offer.respond', $token), [
            'response' => TeacherRegistrationRequest::OFFER_ACCEPTED,
        ])->assertRedirect(route('teacher-registration.offer', $token));

        $application->refresh();
        $this->assertSame(TeacherRegistrationRequest::STATUS_OFFER_ACCEPTED, $application->status);

        $this->actingAs($admin)
            ->post(route('admin.teacher-registrations.approve', $application))
            ->assertRedirect(route('admin.teacher-registrations.show', $application));

        $this->assertDatabaseHas('users', [
            'email' => 'doubts@example.com',
            'role' => User::ROLE_TEACHER,
        ]);

        $application->refresh();
        $this->assertSame(TeacherRegistrationRequest::STATUS_APPROVED, $application->status);
    }

    public function test_admin_cannot_approve_while_counter_offer_is_pending(): void
    {
        ['board' => $board, 'grades' => $grades] = $this->seedPrerequisites();
        $gradeIds = [$grades->first()->id];

        $this->post(
            route('teacher-registration.store'),
            $this->validPayload($board->id, $gradeIds, $gradeIds, 'wait@example.com', false, true),
        );

        $application = TeacherRegistrationRequest::query()->where('email', 'wait@example.com')->firstOrFail();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $application->update([
            'status' => TeacherRegistrationRequest::STATUS_COUNTER_OFFERED,
            'counter_offer_token' => 'test-token',
            'counter_hourly_rate_inr' => 600,
            'agreed_to_mentoring_program' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.teacher-registrations.show', $application))
            ->post(route('admin.teacher-registrations.approve', $application))
            ->assertRedirect(route('admin.teacher-registrations.show', $application))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'wait@example.com']);
    }

    public function test_teacher_can_submit_book_content_upload_application(): void
    {
        Mail::fake();

        ['board' => $board, 'grades' => $grades] = $this->seedPrerequisites();

        $response = $this->post(route('teacher-registration.store'), [
            ...$this->validPayload($board->id, [$grades[2]->id], [], 'book@example.com', true, false),
            'interested_in_book_content_upload' => true,
            'proposed_rate_per_set_inr' => 600,
        ]);

        $response->assertRedirect(route('teacher-registration.thank-you'));

        $this->assertDatabaseHas('teacher_registration_requests', [
            'email' => 'book@example.com',
            'interested_in_book_content_upload' => true,
            'proposed_rate_per_set_inr' => 600,
            'status' => TeacherRegistrationRequest::STATUS_PENDING,
        ]);
    }

    public function test_form_only_lists_classes_five_through_twelve(): void
    {
        $this->withoutVite();
        GradeLevel::query()->create(['name' => 'Class 4', 'sort_order' => 4, 'is_active' => true]);
        $this->seedPrerequisites();

        $this->get(route('teacher-registration.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('gradeLevels', 8)
                ->where('gradeLevels.0.sort_order', 5)
                ->where('gradeLevels.7.sort_order', 12));
    }
}
