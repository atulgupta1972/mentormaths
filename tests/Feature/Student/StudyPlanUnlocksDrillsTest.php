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
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
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
        ['user' => $user] = $this->seedStudent(withStudyPlan: false, pastFirstDay: true);

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.school-study-plan.show'));
    }

    public function test_after_study_plan_marked_dashboard_forces_formula_drill(): void
    {
        ['user' => $user] = $this->seedStudent(withStudyPlan: true, pastFirstDay: true);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.formula-drill.show'));
    }

    public function test_first_day_without_study_plan_opens_the_planner(): void
    {
        ['user' => $user] = $this->seedStudent(withStudyPlan: false, pastFirstDay: false);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('promptStudyPlan', true));

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.school-study-plan.show'))
            ->assertSessionHas('warning');
    }

    public function test_first_day_skips_drills_even_with_study_plan(): void
    {
        ['user' => $user] = $this->seedStudent(withStudyPlan: true, pastFirstDay: false);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_student_can_self_assign_from_study_plan_before_daily_drills(): void
    {
        ['user' => $user] = $this->seedStudent(withStudyPlan: true, pastFirstDay: true);
        $chapter = SyllabusChapter::query()->firstOrFail();
        $worksheet = Worksheet::query()->create([
            'title' => 'Practice 1',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $this->actingAs($user)
            ->from(route('student.school-study-plan.show'))
            ->post(route('student.worksheets.self-assign', $worksheet))
            ->assertRedirect(route('student.school-study-plan.show'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('set_assignments', [
            'worksheet_id' => $worksheet->id,
            'status' => 'assigned',
        ]);
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
    private function seedStudent(bool $withStudyPlan, bool $pastFirstDay = true): array
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

        if ($pastFirstDay) {
            $yesterday = now()->subDay();
            $user->forceFill(['created_at' => $yesterday])->save();
            $student->forceFill(['created_at' => $yesterday])->save();
        }

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
