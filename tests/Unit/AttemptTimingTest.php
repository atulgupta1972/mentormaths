<?php

namespace Tests\Unit;

use App\Models\SetAttempt;
use App\Support\AttemptTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttemptTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_elapsed_seconds_is_never_negative(): void
    {
        $startedAt = now()->addHour();

        $elapsed = AttemptTiming::elapsedSeconds($startedAt);

        $this->assertGreaterThanOrEqual(3599, $elapsed);
        $this->assertLessThanOrEqual(3601, $elapsed);
    }

    public function test_elapsed_seconds_for_normal_attempt(): void
    {
        $startedAt = now()->subMinutes(5);

        $elapsed = AttemptTiming::elapsedSeconds($startedAt);

        $this->assertGreaterThanOrEqual(299, $elapsed);
        $this->assertLessThanOrEqual(301, $elapsed);
    }

    public function test_pause_and_resume_accumulates_active_seconds(): void
    {
        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $this->seedAssignmentId(),
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subHours(3),
            'active_seconds' => 120,
            'active_session_started_at' => now()->subSeconds(30),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        AttemptTiming::pauseSession($attempt);
        $attempt->refresh();

        $this->assertNull($attempt->active_session_started_at);
        $this->assertGreaterThanOrEqual(149, $attempt->active_seconds);
        $this->assertLessThanOrEqual(151, $attempt->active_seconds);

        AttemptTiming::resumeSession($attempt->fresh());
        $attempt->refresh();

        $this->assertNotNull($attempt->active_session_started_at);
        $this->assertSame(150, $attempt->active_seconds);
    }

    public function test_finalize_uses_active_seconds_not_wall_clock(): void
    {
        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $this->seedAssignmentId(),
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subHours(5),
            'active_seconds' => 240,
            'active_session_started_at' => now()->subSeconds(60),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        $total = AttemptTiming::finalizeActiveTime($attempt);

        $this->assertGreaterThanOrEqual(299, $total);
        $this->assertLessThanOrEqual(301, $total);
        $this->assertNull($attempt->fresh()->active_session_started_at);
    }

    private function seedAssignmentId(): int
    {
        $year = \App\Models\AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = \App\Models\Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = \App\Models\GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $student = \App\Models\Student::query()->create([
            'name' => 'Timer Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $enrollment = \App\Models\StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => \App\Models\StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = \App\Models\Worksheet::query()->create([
            'title' => 'Set',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => \App\Support\PracticeSetTier::STARTER,
            'scope' => \App\Support\PracticeSetScope::TOPIC,
            'status' => \App\Models\Worksheet::STATUS_PUBLISHED,
        ]);

        return \App\Models\SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => \App\Models\SetAssignment::STATUS_IN_PROGRESS,
        ])->id;
    }
}
