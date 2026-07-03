<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowseAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_student_can_browse_classes_and_questions(): void
    {
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'is_active' => true,
        ]);

        $this->actingAs($student)
            ->get(route('admin.classes.index'))
            ->assertOk();

        $this->actingAs($student)
            ->get(route('admin.questions.index'))
            ->assertOk();
    }

    public function test_authenticated_student_cannot_access_admin_users(): void
    {
        $student = User::factory()->create([
            'role' => User::ROLE_STUDENT,
            'is_active' => true,
        ]);

        $this->actingAs($student)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }
}
