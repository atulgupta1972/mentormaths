<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Worksheet;
use App\Services\SetAttemptService;
use App\Support\AttemptIntegrity;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttemptIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_attempts_use_strict_mode_when_class_protection_enabled(): void
    {
        [$enrollment] = $this->seedEnrollment(protectTests: true, protectPractice: true);

        $config = AttemptIntegrity::configFor($enrollment, true);

        $this->assertTrue($config['enabled']);
        $this->assertSame('strict', $config['mode']);
        $this->assertTrue($config['require_fullscreen']);
        $this->assertTrue($config['track_tab_leaves']);
    }

    public function test_practice_attempts_use_light_mode_when_class_protection_enabled(): void
    {
        [$enrollment] = $this->seedEnrollment(protectTests: true, protectPractice: true);

        $config = AttemptIntegrity::configFor($enrollment, false);

        $this->assertTrue($config['enabled']);
        $this->assertSame('light', $config['mode']);
        $this->assertFalse($config['require_fullscreen']);
        $this->assertTrue($config['track_tab_leaves']);
    }

    public function test_class_can_disable_protection_per_mode(): void
    {
        [$enrollment] = $this->seedEnrollment(protectTests: false, protectPractice: false);

        $this->assertSame('off', AttemptIntegrity::configFor($enrollment, true)['mode']);
        $this->assertSame('off', AttemptIntegrity::configFor($enrollment, false)['mode']);
    }

    public function test_record_tab_leave_increments_attempt_counter(): void
    {
        [$enrollment, $assignment] = $this->seedEnrollmentWithAssignment();

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        $service = app(SetAttemptService::class);
        $service->recordTabLeave($attempt);
        $service->recordTabLeave($attempt->fresh());

        $this->assertSame(2, $attempt->fresh()->tab_leave_count);
    }

    /**
     * @return array{0: StudentEnrollment}
     */
    private function seedEnrollment(bool $protectTests, bool $protectPractice): array
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
            'protect_test_attempts' => $protectTests,
            'protect_practice_attempts' => $protectPractice,
        ]);
        Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

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

        $enrollment->load('gradeLevel');

        return [$enrollment];
    }

    /**
     * @return array{0: StudentEnrollment, 1: SetAssignment}
     */
    private function seedEnrollmentWithAssignment(): array
    {
        [$enrollment] = $this->seedEnrollment(true, true);

        $worksheet = Worksheet::query()->create([
            'title' => 'Test set',
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

        return [$enrollment, $assignment];
    }
}
