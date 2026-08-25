<?php

namespace Tests\Feature\Student;

use App\Mail\StudentOnboardingProcess;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Group;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudyPlanUnlocksDrillsTest extends TestCase
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

    public function test_dashboard_does_not_force_drills_before_study_plan(): void
    {
        ['user' => $user] = $this->seedStudent(withStudyPlan: false);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_formula_drill_redirects_to_study_plan_until_marked(): void
    {
        ['user' => $user] = $this->seedStudent(withStudyPlan: false);

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.school-study-plan.show'));
    }

    public function test_after_study_plan_marked_dashboard_forces_formula_drill(): void
    {
        ['user' => $user] = $this->seedStudent(withStudyPlan: true);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.formula-drill.show'));
    }

    public function test_student_signup_sends_onboarding_process_email(): void
    {
        Mail::fake();

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $this->post(route('registration.store'), [
            'student_name' => 'Process Student',
            'student_mobile' => '9876543299',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543298',
            'parent1_email' => 'parent.process@example.com',
            'school_name' => 'Demo School',
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'email' => 'process.student@example.com',
            'notify_parent1_mobile' => true,
            'notify_student_mobile' => false,
            'enrollment_source' => 'individual',
        ])->assertRedirect(route('registration.thank-you'));

        Mail::assertSent(StudentOnboardingProcess::class, function (StudentOnboardingProcess $mail) {
            return $mail->hasTo('process.student@example.com')
                || $mail->hasTo('parent.process@example.com');
        });
    }

    /**
     * @return array{student: Student, user: User}
     */
    private function seedStudent(bool $withStudyPlan): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->firstOrCreate(['code' => 'MATHS'], ['name' => 'Mathematics']);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Gate Student',
            'parent1_name' => 'P',
            'parent1_mobile' => '9876500111',
            'school_name' => 'S',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'S',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        if ($withStudyPlan) {
            $syllabus = SyllabusVersion::query()->create([
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
                'board_id' => $board->id,
                'subject_id' => $subject->id,
                'status' => SyllabusVersion::STATUS_PUBLISHED,
            ]);
            $chapter = SyllabusChapter::query()->create([
                'syllabus_version_id' => $syllabus->id,
                'chapter_number' => 1,
                'name' => 'Integers',
                'sort_order' => 1,
            ]);
            StudentChapterCoverage::query()->create([
                'student_enrollment_id' => $enrollment->id,
                'syllabus_chapter_id' => $chapter->id,
                'status' => StudentChapterCoverage::STATUS_STUDIED,
            ]);
        }

        return compact('student', 'user');
    }
}
