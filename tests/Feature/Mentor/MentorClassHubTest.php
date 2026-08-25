<?php

namespace Tests\Feature\Mentor;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentorClassHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['admin', 'teacher', 'mentor', 'student'] as $code) {
            Group::query()->firstOrCreate(
                ['code' => $code],
                ['name' => ucfirst($code), 'is_active' => true],
            );
        }
    }

    public function test_mentor_dashboard_redirects_to_their_classes(): void
    {
        $mentor = $this->makeMentor();

        $this->actingAs($mentor)
            ->get(route('dashboard'))
            ->assertRedirect(route('mentor.classes.index'));
    }

    public function test_mentor_only_sees_own_class_students(): void
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);
        $board = Board::query()->create([
            'code' => 'CBSE',
            'name' => 'CBSE',
            'is_active' => true,
        ]);

        $mentor = $this->makeMentor();
        $otherMentor = $this->makeMentor('other.mentor@example.com');

        $mine = CoachingClass::query()->create(['name' => 'Mine Tuition', 'is_active' => true]);
        $theirs = CoachingClass::query()->create(['name' => 'Other Tuition', 'is_active' => true]);

        $myTeacher = CoachingClassTeacher::query()->create([
            'coaching_class_id' => $mine->id,
            'name' => 'Me',
            'mobile' => '9876500011',
            'user_id' => $mentor->id,
            'is_active' => true,
        ]);
        $theirTeacher = CoachingClassTeacher::query()->create([
            'coaching_class_id' => $theirs->id,
            'name' => 'Them',
            'mobile' => '9876500012',
            'user_id' => $otherMentor->id,
            'is_active' => true,
        ]);

        $myStudent = Student::query()->create([
            'name' => 'My Student',
            'parent1_name' => 'P',
            'parent1_mobile' => '9876500013',
            'school_name' => 'S',
            'enrollment_source' => 'coaching',
            'coaching_class_id' => $mine->id,
            'coaching_class_teacher_id' => $myTeacher->id,
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
            'notify_student_mobile' => false,
        ]);
        $otherStudent = Student::query()->create([
            'name' => 'Other Student',
            'parent1_name' => 'P',
            'parent1_mobile' => '9876500014',
            'school_name' => 'S',
            'enrollment_source' => 'coaching',
            'coaching_class_id' => $theirs->id,
            'coaching_class_teacher_id' => $theirTeacher->id,
            'notify_parent1_mobile' => true,
            'notify_parent2_mobile' => false,
            'notify_student_mobile' => false,
        ]);

        foreach ([$myStudent, $otherStudent] as $student) {
            StudentEnrollment::query()->create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
                'board_id' => $board->id,
                'school_name' => 'Demo School',
                'status' => StudentEnrollment::STATUS_ACTIVE,
                'enrollment_source' => 'coaching',
                'coaching_class_id' => $student->coaching_class_id,
            ]);
        }

        $this->actingAs($mentor)
            ->get(route('mentor.classes.show', $mine->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mentor/Classes/Show')
                ->has('examPlanRows', 1)
                ->where('examPlanRows.0.student_name', 'My Student'));

        $this->actingAs($mentor)
            ->get(route('mentor.classes.show', $theirs->id))
            ->assertForbidden();

        $this->actingAs($mentor)
            ->get(route('admin.classes.index'))
            ->assertRedirect(route('mentor.classes.index'));
    }

    private function makeMentor(string $email = 'mentor@example.com'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => User::ROLE_TEACHER,
            'is_active' => true,
        ]);

        app(UserGroupService::class)->attachGroupByCode($user, User::ROLE_TEACHER);
        app(UserGroupService::class)->attachGroupByCode($user, User::ROLE_MENTOR);

        return $user->fresh();
    }
}
