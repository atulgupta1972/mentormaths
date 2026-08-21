<?php

namespace Tests\Feature\Admin;

use App\Mail\SchoolStudyPlanReminder;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SetAssignment;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\UserGroupService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SchoolStudyPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_admin_can_view_and_update_student_school_study_plan(): void
    {
        [$admin, $student, $grade, $chapters] = $this->seedAdminAndStudent();

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.school-study-plan.index', ['student_id' => $student->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SchoolStudyPlan/Index')
                ->where('selectedStudent.id', $student->id)
                ->has('classCoverage.chapters', 3)
                ->has('examPlans')
                ->has('syllabusChapters', 3)
                ->has('examTypeOptions')
                ->where('summary.without_plan', 1)
                ->where('summary.with_plan', 0));

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->put(route('admin.school-study-plan.update', [$student, $chapters[1]]), [
                'status' => 'under_study',
            ])
            ->assertRedirect();

        $enrollmentId = $student->enrollments()->first()->id;

        $this->assertDatabaseMissing('student_chapter_coverages', [
            'student_enrollment_id' => $enrollmentId,
            'syllabus_chapter_id' => $chapters[0]->id,
        ]);
        $this->assertDatabaseHas('student_chapter_coverages', [
            'student_enrollment_id' => $enrollmentId,
            'syllabus_chapter_id' => $chapters[1]->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->put(route('admin.school-study-plan.update', [$student, $chapters[1]]), [
                'status' => 'none',
            ])
            ->assertRedirect(route('admin.school-study-plan.index', ['student_id' => $student->id]));

        $this->assertDatabaseMissing('student_chapter_coverages', [
            'student_enrollment_id' => $enrollmentId,
            'syllabus_chapter_id' => $chapters[1]->id,
        ]);
    }

    public function test_admin_can_save_exam_plan_from_school_study_plan_page(): void
    {
        [$admin, $student, $grade, $chapters] = $this->seedAdminAndStudent();

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->from(route('admin.school-study-plan.index', ['student_id' => $student->id]))
            ->post(route('admin.exam-plans.store'), [
                'student_id' => $student->id,
                'exam_date' => now()->addDays(10)->toDateString(),
                'title' => 'Unit test 1',
                'exam_type' => 'unit_test',
                'chapter_selections' => [
                    ['syllabus_chapter_id' => $chapters[0]->id, 'syllabus_topic_ids' => null],
                    ['syllabus_chapter_id' => $chapters[1]->id, 'syllabus_topic_ids' => null],
                ],
            ])
            ->assertRedirect(route('admin.school-study-plan.index', ['student_id' => $student->id]));

        $this->assertDatabaseHas('exam_plans', [
            'title' => 'Unit test 1',
            'student_enrollment_id' => $student->enrollments()->first()->id,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.school-study-plan.index', ['student_id' => $student->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('examPlans', 1)
                ->has('upcomingExams', 1)
                ->where('upcomingExams.0.title', 'Unit test 1'));
    }

    public function test_admin_sees_breakup_of_students_with_and_without_study_plan(): void
    {
        [$admin, $withoutPlan, $withPlan, $grade] = $this->seedTwoStudentsOneWithPlan();

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.school-study-plan.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SchoolStudyPlan/Index')
                ->where('summary.total', 2)
                ->where('summary.with_plan', 1)
                ->where('summary.without_plan', 1)
                ->where('withPlanStudents.0.id', $withPlan->id)
                ->where('withoutPlanStudents.0.id', $withoutPlan->id));
    }

    public function test_admin_can_email_students_without_study_plan(): void
    {
        Mail::fake();

        [$admin, $withoutPlan, $withPlan, $grade] = $this->seedTwoStudentsOneWithPlan();

        $withoutPlan->update([
            'email' => 'noplan@example.com',
            'parent1_email' => 'parent-noplan@example.com',
        ]);
        $withPlan->update([
            'email' => 'hasplan@example.com',
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.school-study-plan.send-reminders'))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(SchoolStudyPlanReminder::class, function (SchoolStudyPlanReminder $mail) use ($withoutPlan) {
            return $mail->student->is($withoutPlan)
                && $mail->hasTo('noplan@example.com');
        });

        Mail::assertNotSent(SchoolStudyPlanReminder::class, function (SchoolStudyPlanReminder $mail) use ($withPlan) {
            return $mail->student->is($withPlan);
        });
    }

    public function test_admin_can_assign_worksheet_from_study_plan_with_today_target(): void
    {
        [$admin, $student, $grade, $chapters] = $this->seedAdminAndStudent();
        $worksheet = $this->seedChapterWorksheet($chapters[1], $admin);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->from(route('admin.school-study-plan.index', ['student_id' => $student->id]))
            ->post(route('admin.practice-sets.assign', $worksheet), [
                'student_id' => $student->id,
                'target_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('set_assignments', [
            'worksheet_id' => $worksheet->id,
            'status' => SetAssignment::STATUS_ASSIGNED,
            'due_date' => now()->toDateString(),
        ]);
    }

    public function test_mentor_can_open_study_plan_and_assign_worksheet(): void
    {
        [$admin, $student, $grade, $chapters] = $this->seedAdminAndStudent();
        $worksheet = $this->seedChapterWorksheet($chapters[1], $admin);

        $mentor = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($mentor, User::ROLE_MENTOR);

        $this->actingAs($mentor)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.school-study-plan.index', ['student_id' => $student->id]))
            ->assertOk();

        $this->actingAs($mentor)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.practice-sets.assign', $worksheet), [
                'student_id' => $student->id,
                'target_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    /**
     * @return array{0: User, 1: Student, 2: GradeLevel, 3: list<SyllabusChapter>}
     */
    private function seedAdminAndStudent(): array
    {
        return $this->seedClassWithStudent('Plan Student');
    }

    /**
     * @return array{0: User, 1: Student, 2: Student, 3: GradeLevel}
     */
    private function seedTwoStudentsOneWithPlan(): array
    {
        [$admin, $withoutPlan, $grade, $chapters] = $this->seedClassWithStudent('No Plan Student');

        $withUser = User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'withplan-login@example.com']);
        $withPlan = Student::query()->create([
            'user_id' => $withUser->id,
            'name' => 'Has Plan Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543211',
            'school_name' => 'School',
        ]);

        $year = AcademicYear::active();
        $board = Board::query()->first();

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $withPlan->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[0]->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
            'marked_under_study_at' => now(),
        ]);

        return [$admin, $withoutPlan, $withPlan, $grade];
    }

    /**
     * @return array{0: User, 1: Student, 2: GradeLevel, 3: list<SyllabusChapter>}
     */
    private function seedClassWithStudent(string $studentName): array
    {
        $year = AcademicYear::query()->firstOrCreate(
            ['name' => '2026-27'],
            [
                'starts_on' => '2026-03-01',
                'ends_on' => '2027-02-28',
                'is_active' => true,
            ],
        );

        $board = Board::query()->firstOrCreate(
            ['code' => 'CBSE'],
            ['name' => 'CBSE', 'is_active' => true],
        );
        $grade = GradeLevel::query()->firstOrCreate(
            ['name' => 'Class 8'],
            ['sort_order' => 8, 'is_active' => true],
        );
        $subject = Subject::query()->firstOrCreate(
            ['code' => 'MATHS'],
            ['name' => 'Mathematics'],
        );

        $syllabus = SyllabusVersion::query()->firstOrCreate(
            [
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
                'board_id' => $board->id,
                'subject_id' => $subject->id,
            ],
            [],
        );

        $chapters = SyllabusChapter::query()
            ->where('syllabus_version_id', $syllabus->id)
            ->orderBy('sort_order')
            ->get();

        if ($chapters->count() < 3) {
            $chapters = collect();
            for ($i = 1; $i <= 3; $i++) {
                $chapters->push(SyllabusChapter::query()->create([
                    'syllabus_version_id' => $syllabus->id,
                    'name' => "Chapter {$i}",
                    'chapter_number' => $i,
                    'sort_order' => $i,
                ]));
            }
        }

        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'name' => $studentName,
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$admin, $student, $grade, $chapters->values()->all()];
    }

    private function seedChapterWorksheet(SyllabusChapter $chapter, User $admin): Worksheet
    {
        return Worksheet::query()->create([
            'title' => $chapter->name.' practice',
            'set_number' => 2,
            'set_code' => 'P2',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);
    }
}
