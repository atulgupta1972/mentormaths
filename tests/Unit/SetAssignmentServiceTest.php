<?php

namespace Tests\Unit;

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
use App\Services\SetAssignmentService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\StudentIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_assign_allows_same_set_after_previous_assignment_completed(): void
    {
        [$enrollment, $worksheet, $assigner] = $this->seedAssignmentContext();

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $assigner->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        $service = app(SetAssignmentService::class);
        $assignment = $service->assign($worksheet, $enrollment, $assigner, now()->addWeeks(2)->toDateString());

        $this->assertSame(SetAssignment::STATUS_ASSIGNED, $assignment->status);
        $this->assertSame(2, SetAssignment::query()->where('student_enrollment_id', $enrollment->id)->count());
    }

    public function test_assign_reassigns_when_active_assignment_exists(): void
    {
        [$enrollment, $worksheet, $assigner] = $this->seedAssignmentContext();

        $existing = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $assigner->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $service = app(SetAssignmentService::class);
        $assignment = $service->assign($worksheet, $enrollment, $assigner, now()->addWeeks(2)->toDateString(), 'Try again');

        $this->assertSame($existing->id, $assignment->id);
        $this->assertSame('Try again', $assignment->notes);
        $this->assertNotNull($assignment->reassigned_at);
        $this->assertSame(1, SetAssignment::query()->where('student_enrollment_id', $enrollment->id)->count());
    }

    public function test_can_reuse_student_profile_without_login(): void
    {
        $student = Student::query()->create([
            'name' => 'Riya Sharma',
            'student_mobile' => '9876543210',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $this->assertTrue(StudentIdentity::canReuseStudentProfile($student));
        $this->assertFalse(StudentIdentity::hasDuplicate('Riya Sharma', '9876543210'));
    }

    public function test_cannot_reuse_student_profile_with_active_login(): void
    {
        $user = User::factory()->create(['is_active' => true, 'role' => User::ROLE_STUDENT]);
        Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Riya Sharma',
            'student_mobile' => '9876543210',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $this->assertTrue(StudentIdentity::hasDuplicate('Riya Sharma', '9876543210'));
    }

    /**
     * @return array{0: StudentEnrollment, 1: Worksheet, 2: User}
     */
    private function seedAssignmentContext(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Test Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter set',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $assigner = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$enrollment, $worksheet, $assigner];
    }
}
