<?php

namespace Tests\Feature\Mentor;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
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

    public function test_mentor_only_sees_own_class_students_on_grade_hub_and_study_plan(): void
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

        $myEnrollment = null;
        $otherEnrollment = null;

        foreach ([$myStudent, $otherStudent] as $student) {
            $enrollment = StudentEnrollment::query()->create([
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
                'board_id' => $board->id,
                'school_name' => 'Demo School',
                'status' => StudentEnrollment::STATUS_ACTIVE,
                'enrollment_source' => 'coaching',
                'coaching_class_id' => $student->coaching_class_id,
            ]);

            if ($student->id === $myStudent->id) {
                $myEnrollment = $enrollment;
            } else {
                $otherEnrollment = $enrollment;
            }
        }

        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $otherEnrollment->id,
            'syllabus_chapter_id' => $this->makeChapter($year, $grade, $board)->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);

        $this->actingAs($mentor)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('mentor.classes.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mentor/Classes/Index')
                ->has('classes')
                ->where('classes', fn ($classes) => collect($classes)->contains(
                    fn ($card) => $card['id'] === $grade->id && $card['students_count'] === 1
                )));

        $this->actingAs($mentor)
            ->get(route('mentor.classes.show', $grade->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Mentor/Classes/Show')
                ->has('examPlanRows', 1)
                ->where('examPlanRows.0.student_name', 'My Student'));

        $this->actingAs($mentor)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.school-study-plan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SchoolStudyPlan/Index')
                ->has('withPlanStudents', 0)
                ->has('students', 1)
                ->where('students.0.name', 'My Student')
                ->where('summary.total', 1)
                ->where('summary.with_plan', 0));

        $this->actingAs($mentor)
            ->get(route('admin.school-study-plan.index', ['student_id' => $otherStudent->id]))
            ->assertForbidden();

        $this->actingAs($mentor)
            ->get(route('admin.questions.coverage'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/ContentCoverage')
                ->where('browseOnly', true));

        $this->actingAs($mentor)
            ->get(route('admin.classes.index'))
            ->assertRedirect(route('mentor.classes.index'));
    }

    private function makeChapter(AcademicYear $year, GradeLevel $grade, Board $board): SyllabusChapter
    {
        $maths = Subject::query()->firstOrCreate(
            ['code' => 'MATHS'],
            ['name' => 'Mathematics'],
        );

        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $maths->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);

        return SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => 1,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
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
