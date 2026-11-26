<?php

namespace Tests\Feature\Admin;

use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Models\User;
use App\Support\EnrollmentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentorDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_update_mentor_mobile_for_all_mapped_students(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $class = CoachingClass::query()->create([
            'name' => 'Bright Tuition',
            'city' => 'Delhi',
            'is_active' => true,
        ]);

        $teacher = CoachingClassTeacher::query()->create([
            'coaching_class_id' => $class->id,
            'name' => 'Neeru Gupta',
            'mobile' => '9625522556',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Vishvesh',
            'school_name' => 'DPS',
            'parent1_name' => 'Neeru Gupta',
            'parent1_mobile' => '9625522556',
            'notify_parent1_mobile' => true,
            'enrollment_source' => EnrollmentSource::COACHING,
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mentors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Mentors/Index')
                ->has('teachers', 1)
                ->where('teachers.0.mobile', '9625522556')
                ->where('teachers.0.students_count', 1)
            );

        $this->actingAs($admin)
            ->patch(route('admin.mentors.teachers.update', $teacher), [
                'name' => 'Neeru Gupta',
                'mobile' => '9212689124',
                'sync_linked_user_mobile' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('coaching_class_teachers', [
            'id' => $teacher->id,
            'mobile' => '9212689124',
        ]);

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'parent1_mobile' => '9212689124',
        ]);
    }
}
