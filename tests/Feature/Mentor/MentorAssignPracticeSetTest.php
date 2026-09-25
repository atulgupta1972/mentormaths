<?php

namespace Tests\Feature\Mentor;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\UserGroupService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentorAssignPracticeSetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_mentor_sees_assignment_panel_on_question_bank_set_and_can_assign(): void
    {
        [$mentor, $student, $worksheet] = $this->seedMentorStudentAndWorksheet();

        $this->actingAs($mentor)
            ->get(route('admin.questions.sets.show', $worksheet))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetQuestions')
                ->where('canViewQuestions', false)
                ->has('assignmentPanel.students', 1)
                ->where('assignmentPanel.students.0.id', $student->id));

        $this->actingAs($mentor)
            ->post(route('admin.practice-sets.assign-students', $worksheet), [
                'student_ids' => [$student->id],
                'target_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('set_assignments', [
            'worksheet_id' => $worksheet->id,
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);
    }

    public function test_mentor_can_assign_chapter_from_a_different_class(): void
    {
        [$mentor, $student] = $this->seedMentorAndClass8Student();
        $otherGradeWorksheet = $this->seedPublishedWorksheetForGrade('Class 9', 9, 'S911');

        $this->actingAs($mentor)
            ->post(route('admin.practice-sets.assign', $otherGradeWorksheet), [
                'student_id' => $student->id,
                'target_date' => now()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('set_assignments', [
            'worksheet_id' => $otherGradeWorksheet->id,
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);
    }

    public function test_mentor_can_reassign_existing_assignment(): void
    {
        [$mentor, $student, $worksheet] = $this->seedMentorStudentAndWorksheet();
        $enrollment = $student->enrollments()->first();

        $assignment = SetAssignment::query()->create([
            'worksheet_id' => $worksheet->id,
            'student_enrollment_id' => $enrollment->id,
            'assigned_by' => $mentor->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->subDay()->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $newDate = now()->addDays(3)->toDateString();

        $this->actingAs($mentor)
            ->post(route('admin.set-assignments.reassign', $assignment), [
                'target_date' => $newDate,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $assignment->refresh();
        $this->assertSame(SetAssignment::STATUS_ASSIGNED, $assignment->status);
        $this->assertSame($newDate, $assignment->due_date?->toDateString());
    }

    /**
     * @return array{0: User, 1: Student, 2: Worksheet}
     */
    private function seedMentorStudentAndWorksheet(): array
    {
        [$mentor, $student] = $this->seedMentorAndClass8Student();
        $worksheet = $this->seedPublishedWorksheetForGrade('Class 8', 8, 'S811');

        return [$mentor, $student, $worksheet];
    }

    /**
     * @return array{0: User, 1: Student}
     */
    private function seedMentorAndClass8Student(): array
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

        $mentor = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($mentor, User::ROLE_MENTOR);

        $student = Student::query()->create([
            'name' => 'Mentee Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
            'mentor_user_id' => $mentor->id,
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        return [$mentor, $student];
    }

    private function seedPublishedWorksheetForGrade(string $gradeName, int $sortOrder, string $setCode): Worksheet
    {
        $year = AcademicYear::query()->where('is_active', true)->firstOrFail();
        $board = Board::query()->where('code', 'CBSE')->firstOrFail();
        $grade = GradeLevel::query()->firstOrCreate(
            ['name' => $gradeName],
            ['sort_order' => $sortOrder, 'is_active' => true],
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
            ['status' => SyllabusVersion::STATUS_PUBLISHED],
        );

        $chapter = SyllabusChapter::query()->firstOrCreate(
            [
                'syllabus_version_id' => $syllabus->id,
                'chapter_number' => 1,
            ],
            [
                'name' => "{$gradeName} Ch 1",
                'sort_order' => 1,
            ],
        );

        $topic = SyllabusTopic::query()->firstOrCreate(
            [
                'syllabus_chapter_id' => $chapter->id,
                'topic_number' => 1,
            ],
            [
                'name' => 'Topic 1',
                'sort_order' => 1,
            ],
        );

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return Worksheet::query()->create([
            'title' => "{$gradeName} practice",
            'set_number' => 1,
            'set_code' => $setCode,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $admin->id,
        ]);
    }
}
