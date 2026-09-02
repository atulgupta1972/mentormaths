<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsureBasicsDrillComplete;
use App\Http\Middleware\EnsureFormulaDrillComplete;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\ClassCoverageService;
use App\Services\DashboardService;
use App\Services\QuestionResolutionService;
use App\Services\UserGroupService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_loads_with_an_active_student(): void
    {
        $this->withoutVite();

        [$admin] = $this->seedAdminDashboard();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isAdmin', true)
                ->where('stats.students_count', 1)
                ->has('gradeLevels', 1)
                ->where('gradeLevels.0.students_count', 1));
    }

    public function test_admin_can_load_one_student_dashboard_detail(): void
    {
        $this->withoutVite();

        [$admin, $enrollment] = $this->seedAdminDashboard();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.student', $enrollment->student_id))
            ->assertOk()
            ->assertJsonPath('student_id', $enrollment->student_id)
            ->assertJsonPath('help_requests_count', 0);
    }

    public function test_admin_dashboard_still_loads_when_help_requests_fail(): void
    {
        $this->withoutVite();

        [$admin] = $this->seedAdminDashboard();

        $this->mock(QuestionResolutionService::class, function ($mock) {
            $mock->shouldReceive('pendingForStudentIds')
                ->andThrow(new \RuntimeException('help requests unavailable'));
        });

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isAdmin', true)
                ->where('stats.students_count', 1)
                ->where('helpRequests', []));
    }

    public function test_admin_dashboard_lists_published_content_for_recheck(): void
    {
        $this->withoutVite();

        [$admin, $enrollment] = $this->seedAdminDashboard();
        $grade = $enrollment->gradeLevel;

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $year = AcademicYear::active();
        $board = Board::query()->first();
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Polynomials',
            'chapter_number' => 'Ch 2',
            'sort_order' => 2,
        ]);
        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Polynomials',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);
        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 1000,
            'agreed_amount_inr' => 1000,
            'agreed_at' => now(),
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->loadDeferredProps('default', fn ($deferred) => $deferred
                    ->has('contentRecheckQueue', 1)
                    ->where('contentRecheckQueue.0.id', $task->id)
                    ->where('contentRecheckQueue.0.chapter_title', 'Polynomials')));
    }

    public function test_admin_dashboard_lists_locked_attempts_for_unlock(): void
    {
        $this->withoutVite();

        [$admin, $enrollment] = $this->seedAdminDashboard();

        $worksheet = Worksheet::query()->create([
            'title' => 'Chapter test',
            'set_number' => 1,
            'set_code' => 'T701',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_IN_PROGRESS,
        ]);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
            'tab_leave_count' => 4,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $enrollment->grade_level_id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('stats.locked_attempts_count', 1)
                ->has('lockedAttempts', 1)
                ->where('lockedAttempts.0.attempt_id', $attempt->id)
                ->where('lockedAttempts.0.student_name', 'Dashboard Student')
                ->where('lockedAttempts.0.set_code', 'T701'));

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('admin.set-attempts.unlock', $attempt))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, $attempt->fresh()->tab_leave_count);
    }

    public function test_student_dashboard_still_loads_when_study_plan_fails(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware([
            EnsureFormulaDrillComplete::class,
            EnsureBasicsDrillComplete::class,
        ]);

        [$admin, $enrollment] = $this->seedAdminDashboard();
        $user = $enrollment->student->user;

        $this->mock(ClassCoverageService::class, function ($mock) {
            $mock->shouldReceive('forEnrollment')
                ->andThrow(new \RuntimeException('missing column effective_syllabus_chapter_id'));
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isAdmin', false)
                ->loadDeferredProps('default', fn ($deferred) => $deferred
                    ->where('classCoverage.load_error', fn ($message) => str_contains($message, 'study plan'))));
    }

    public function test_student_deferred_load_skips_full_dashboard_query(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware([
            EnsureFormulaDrillComplete::class,
            EnsureBasicsDrillComplete::class,
        ]);

        [, $enrollment] = $this->seedAdminDashboard();
        $user = $enrollment->student->user;

        $emptyStudentPayload = [
            'resumeItems' => [],
            'followUpItems' => [],
            'upcomingExams' => [],
            'stats' => [
                'upcoming_exams' => 0,
                'past_exams' => 0,
                'sets_todo' => 0,
                'sets_under_review' => 0,
                'sets_done' => 0,
                'resolution_count' => 0,
            ],
            'resolutionItems' => [],
            'resolutionCount' => 0,
        ];

        $this->mock(DashboardService::class, function ($mock) use ($emptyStudentPayload) {
            $mock->shouldReceive('forStudent')
                ->once()
                ->andReturn($emptyStudentPayload);
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('isAdmin', false)
                ->loadDeferredProps('default', fn ($deferred) => $deferred
                    ->has('classCoverage')
                    ->has('assignments')));
    }

    public function test_admin_dashboard_survives_grade_context_switch(): void
    {
        $this->withoutVite();

        [$admin, $enrollment] = $this->seedAdminDashboard();
        $gradeSeven = $enrollment->gradeLevel;

        $gradeFive = GradeLevel::query()->create([
            'name' => 'Class 5',
            'sort_order' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $gradeSeven->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('selectedGrade.id', $gradeSeven->id)
                ->where('stats.students_count', 1));

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('admin.grade-context.update'), ['grade_level_id' => $gradeFive->id])
            ->assertRedirect(route('dashboard'));

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $gradeFive->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('selectedGrade.id', $gradeFive->id)
                ->where('stats.students_count', 0)
                ->loadDeferredProps('default', fn ($deferred) => $deferred
                    ->has('contentPublishQueue')
                    ->has('contentRecheckQueue')));

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('admin.grade-context.update'), ['grade_level_id' => null])
            ->assertRedirect(route('dashboard'));

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('selectedGrade', null)
                ->where('stats.students_count', 1));
    }

    /**
     * @return array{0: User, 1: StudentEnrollment}
     */
    private function seedAdminDashboard(): array
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
            'protect_test_attempts' => true,
            'protect_practice_attempts' => true,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Dashboard Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        return [$admin, $enrollment];
    }
}
