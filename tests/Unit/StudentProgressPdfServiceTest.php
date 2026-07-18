<?php

namespace Tests\Unit;

use App\Services\StudentProgressPdfService;
use App\Services\StudentProgressSummaryService;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentProgressPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_output_includes_chart_sections_without_garbled_data_uri_text(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $enrollment = $this->seedCompletedAssignment();
        $summary = app(StudentProgressSummaryService::class)->build($enrollment, now());
        $pdf = app(StudentProgressPdfService::class)->render($summary);

        $this->assertNotSame('', $pdf);
        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertGreaterThan(5000, strlen($pdf));
    }

    private function seedCompletedAssignment(): StudentEnrollment
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $student = Student::query()->create([
            'name' => 'PDF Test Student',
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
            'title' => 'Done set',
            'set_number' => 1,
            'set_code' => 'S901',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now()->subDays(2),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(30),
            'score' => 8,
            'max_score' => 10,
            'time_seconds' => 120,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);

        return $enrollment;
    }
}
