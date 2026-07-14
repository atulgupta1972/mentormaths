<?php

namespace Tests\Unit;

use App\Mail\StudentProgressSummary;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Worksheet;
use App\Services\StudentProgressSummaryService;
use App\Services\StudentProgressWhatsAppService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\StudentProgressMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudentProgressSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_groups_completed_pending_and_review_items(): void
    {
        [$enrollment, $completedAssignment, $pendingAssignment] = $this->seedAssignments();

        SetAttempt::query()->create([
            'set_assignment_id' => $completedAssignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
            'score' => 1,
            'max_score' => 1,
            'time_seconds' => 120,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);

        $summary = app(StudentProgressSummaryService::class)->build($enrollment, now());

        $this->assertSame(1, $summary['stats']['completed_count']);
        $this->assertSame(1, $summary['stats']['pending_count']);
        $this->assertSame('S901', $summary['completed'][0]['set_code']);
        $this->assertSame('S902', $summary['pending'][0]['set_code']);
    }

    public function test_completed_sets_are_ordered_by_submission_date_asc(): void
    {
        [$enrollment, $firstAssignment, $secondAssignment] = $this->seedAssignments();

        $laterWorksheet = Worksheet::query()->create([
            'title' => 'Later set',
            'set_number' => 3,
            'set_code' => 'S903',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $laterAssignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $laterWorksheet->id,
            'assigned_at' => now()->subDays(3),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        SetAttempt::query()->create([
            'set_assignment_id' => $firstAssignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2)->setTime(10, 0),
            'score' => 8,
            'max_score' => 10,
            'time_seconds' => 120,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);

        SetAttempt::query()->create([
            'set_assignment_id' => $laterAssignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay()->setTime(15, 0),
            'score' => 9,
            'max_score' => 10,
            'time_seconds' => 130,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);

        $summary = app(StudentProgressSummaryService::class)->build($enrollment, now());

        $this->assertSame(2, $summary['stats']['completed_count']);
        $this->assertSame(['S901', 'S903'], array_column($summary['completed'], 'set_code'));
        $this->assertArrayHasKey('completed_by_chapter', $summary);
        $this->assertSame(1, $summary['stats']['pending_count']);
        $this->assertSame('S902', $summary['pending'][0]['set_code']);
    }

    public function test_whatsapp_message_includes_completed_and_pending_lines(): void
    {
        [$enrollment, $completedAssignment] = $this->seedAssignments();

        SetAttempt::query()->create([
            'set_assignment_id' => $completedAssignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
            'score' => 1,
            'max_score' => 1,
            'time_seconds' => 120,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);

        $summary = app(StudentProgressSummaryService::class)->build($enrollment, now());
        $student = $enrollment->student;

        $student->update([
            'parent1_mobile' => '9876543210',
            'notify_parent1_mobile' => true,
        ]);

        $message = app(StudentProgressWhatsAppService::class)->buildMessage($summary);

        $this->assertStringContainsString('Progress summary for Test Student', $message);
        $this->assertStringContainsString('Completed (1):', $message);
        $this->assertStringContainsString('100% (1/1)', $message);
        $this->assertStringContainsString('Overall score: 100% (1/1)', $message);
        $this->assertStringContainsString('Date · Set · Type · Topic · Score · Review', $message);
        $this->assertStringContainsString('Pending (1):', $message);
        $this->assertStringContainsString('S902', $message);
    }

    public function test_send_progress_summary_email(): void
    {
        Mail::fake();

        [$enrollment] = $this->seedAssignments();
        $student = $enrollment->student;
        $student->update(['email' => 'parent@example.com']);

        $summary = app(StudentProgressSummaryService::class)->build($enrollment, now());
        $result = StudentProgressMailer::send($student, $summary);

        $this->assertTrue($result['sent']);
        Mail::assertSent(StudentProgressSummary::class, fn (StudentProgressSummary $mail) => $mail->hasTo('parent@example.com'));
    }

    /**
     * @return array{0: StudentEnrollment, 1: SetAssignment, 2: SetAssignment}
     */
    private function seedAssignments(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create([
            'code' => 'CBSE',
            'name' => 'CBSE',
            'is_active' => true,
        ]);

        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        Subject::query()->create([
            'code' => 'MATHS',
            'name' => 'Mathematics',
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

        $completedWorksheet = Worksheet::query()->create([
            'title' => 'Done set',
            'set_number' => 1,
            'set_code' => 'S901',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $pendingWorksheet = Worksheet::query()->create([
            'title' => 'Pending set',
            'set_number' => 2,
            'set_code' => 'S902',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $completedAssignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $completedWorksheet->id,
            'assigned_at' => now()->subDays(2),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        $pendingAssignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $pendingWorksheet->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        return [$enrollment, $completedAssignment, $pendingAssignment];
    }
}
