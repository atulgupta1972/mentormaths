<?php

namespace Tests\Feature\Student;

use App\Http\Middleware\EnsureBasicsDrillComplete;
use App\Http\Middleware\EnsureFormulaDrillComplete;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\EnrollmentSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentMentorDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware([
            EnsureFormulaDrillComplete::class,
            EnsureBasicsDrillComplete::class,
        ]);
    }

    public function test_coaching_student_sees_teacher_on_profile_and_dashboard(): void
    {
        $user = $this->seedCoachingStudent();

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Edit')
                ->where('studentProfile.mentor.mapped', true)
                ->where('studentProfile.mentor.name', 'Ravi Sir')
                ->where('studentProfile.mentor.mobile', '9876500099')
                ->where('studentProfile.mentor.label', 'Coaching teacher')
                ->where('studentProfile.mentor.coaching_class', 'Apex Tuition'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isAdmin', false)
                ->where('mentor.mapped', true)
                ->where('mentor.name', 'Ravi Sir')
                ->where('mentor.mobile', '9876500099')
                ->where('mentor.label', 'Coaching teacher')
                ->where('mentor.coaching_class', 'Apex Tuition'));
    }

    public function test_individual_student_sees_notified_parent_as_mentor(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Aarav',
            'parent1_name' => 'Meera',
            'parent1_mobile' => '9876500011',
            'school_name' => 'Demo',
            'enrollment_source' => EnrollmentSource::INDIVIDUAL,
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Edit')
                ->where('studentProfile.mentor.mapped', true)
                ->where('studentProfile.mentor.name', 'Meera')
                ->where('studentProfile.mentor.mobile', '9876500011')
                ->where('studentProfile.mentor.label', 'Parent (communication)')
                ->where('studentProfile.mentor.coaching_class', null));
    }

    public function test_unmapped_student_sees_empty_mentor_on_profile(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Aarav',
            'parent1_name' => 'Meera',
            'parent1_mobile' => '9876500011',
            'school_name' => 'Demo',
            'enrollment_source' => EnrollmentSource::INDIVIDUAL,
            'notify_parent1_mobile' => false,
            'notify_parent2_mobile' => false,
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Profile/Edit')
                ->where('studentProfile.mentor.mapped', false)
                ->where('studentProfile.mentor.name', null)
                ->where('studentProfile.mentor.mobile', null)
                ->where('studentProfile.mentor.label', 'Not assigned yet'));
    }

    private function seedCoachingStudent(): User
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $class = CoachingClass::query()->create(['name' => 'Apex Tuition', 'is_active' => true]);
        $teacher = CoachingClassTeacher::query()->create([
            'coaching_class_id' => $class->id,
            'name' => 'Ravi Sir',
            'mobile' => '9876500099',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Aarav',
            'parent1_name' => 'Ravi Sir',
            'parent1_mobile' => '9876500099',
            'school_name' => 'Demo School',
            'enrollment_source' => EnrollmentSource::COACHING,
            'coaching_class_id' => $class->id,
            'coaching_class_teacher_id' => $teacher->id,
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
            'notify_student_mobile' => false,
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'school_name' => 'Demo School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
            'enrollment_source' => EnrollmentSource::COACHING,
            'coaching_class_id' => $class->id,
        ]);

        return $user;
    }
}
