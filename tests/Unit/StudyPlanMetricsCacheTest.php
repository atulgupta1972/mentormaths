<?php

namespace Tests\Unit;

use App\Models\AssignmentSumInstance;
use App\Models\SetAssignment;
use App\Services\AssignmentPoolScore;
use App\Services\StudyPlanMetricsCacheService;
use App\Support\AssignmentProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StudyPlanMetricsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_metrics_for_assignment_read_returns_cached_without_pool_queries(): void
    {
        [$enrollment, , $worksheet] = $this->seedMinimalChapterAssignment();

        $question = \App\Models\Question::query()->create([
            'syllabus_topic_id' => $worksheet->syllabus_topic_id,
            'question_text' => 'Q1',
            'type' => \App\Models\Question::TYPE_MCQ,
            'source' => \App\Models\Question::SOURCE_MANUAL,
        ]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
            'cached_pool_metrics' => [
                'pool' => 1,
                'attempted' => 1,
                'correct' => 1,
                'pending' => 0,
                'pending_remedial' => 0,
                'wrong' => 0,
                'completion_pct' => 100,
                'score_pct' => 100,
            ],
            'cached_metrics_at' => now(),
        ]);

        AssignmentSumInstance::query()->create([
            'set_assignment_id' => $assignment->id,
            'student_id' => $enrollment->student_id,
            'worksheet_id' => $worksheet->id,
            'question_id' => $question->id,
            'generation' => 0,
            'status' => AssignmentSumInstance::STATUS_CORRECT,
        ]);

        $poolMock = Mockery::mock(AssignmentPoolScore::class);
        $poolMock->shouldNotReceive('metricsForAssignment');
        $poolMock->shouldNotReceive('rebuildFromAttempts');
        $this->app->instance(AssignmentPoolScore::class, $poolMock);

        $metrics = app(StudyPlanMetricsCacheService::class)->metricsForAssignmentRead($assignment->fresh());

        $this->assertSame(100, $metrics['completion_pct']);
        $this->assertSame(100, $metrics['score_pct']);
    }

    public function test_dashboard_summary_does_not_rebuild_pool_on_read(): void
    {
        [$enrollment, , $practiceOne] = $this->seedMinimalChapterAssignment();

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $practiceOne->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
            'cached_pool_metrics' => [
                'pool' => 4,
                'attempted' => 4,
                'correct' => 3,
                'pending' => 0,
                'pending_remedial' => 0,
                'wrong' => 0,
                'completion_pct' => 100,
                'score_pct' => 75,
            ],
            'cached_metrics_at' => now(),
        ]);

        \App\Models\SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => \App\Models\SetAttempt::MODE_BATCH,
            'started_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
            'score' => 3,
            'max_score' => 4,
            'time_seconds' => 120,
            'status' => \App\Models\SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => \App\Models\SetAttempt::TIMING_ON_TIME,
        ]);

        foreach (range(1, 4) as $index) {
            $question = \App\Models\Question::query()->create([
                'syllabus_topic_id' => $practiceOne->syllabus_topic_id,
                'question_text' => "Q{$index}",
                'type' => \App\Models\Question::TYPE_MCQ,
                'source' => \App\Models\Question::SOURCE_MANUAL,
            ]);

            AssignmentSumInstance::query()->create([
                'set_assignment_id' => $assignment->id,
                'student_id' => $enrollment->student_id,
                'worksheet_id' => $practiceOne->id,
                'question_id' => $question->id,
                'generation' => 0,
                'status' => $index <= 3
                    ? AssignmentSumInstance::STATUS_CORRECT
                    : AssignmentSumInstance::STATUS_WRONG,
            ]);
        }

        $poolMock = Mockery::mock(AssignmentPoolScore::class);
        $poolMock->shouldNotReceive('rebuildFromAttempts');
        $poolMock->shouldNotReceive('metricsForAssignment');
        $poolMock->shouldReceive('isFullyCorrected')->andReturn(false);
        $this->app->instance(AssignmentPoolScore::class, $poolMock);

        $summary = AssignmentProgress::formatStudentDashboardSummary($assignment->fresh(), null);

        $this->assertSame(100, $summary['completion_pct']);
        $this->assertSame(75, $summary['latest_score_percent']);
    }

    public function test_metrics_for_assignment_read_rebuilds_when_cache_is_stale(): void
    {
        [$enrollment, , $practiceOne] = $this->seedMinimalChapterAssignment();

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $practiceOne->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
            'cached_pool_metrics' => [
                'pool' => 4,
                'attempted' => 3,
                'correct' => 2,
                'pending' => 1,
                'pending_remedial' => 0,
                'wrong' => 0,
                'completion_pct' => 75,
                'score_pct' => 67,
            ],
            'cached_metrics_at' => now()->subHour(),
        ]);

        \App\Models\SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => \App\Models\SetAttempt::MODE_BATCH,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'score' => 4,
            'max_score' => 4,
            'time_seconds' => 120,
            'status' => \App\Models\SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => \App\Models\SetAttempt::TIMING_ON_TIME,
        ]);

        foreach (range(1, 4) as $index) {
            $question = \App\Models\Question::query()->create([
                'syllabus_topic_id' => $practiceOne->syllabus_topic_id,
                'question_text' => "Q{$index}",
                'type' => \App\Models\Question::TYPE_MCQ,
                'source' => \App\Models\Question::SOURCE_MANUAL,
            ]);

            AssignmentSumInstance::query()->create([
                'set_assignment_id' => $assignment->id,
                'student_id' => $enrollment->student_id,
                'worksheet_id' => $practiceOne->id,
                'question_id' => $question->id,
                'generation' => 0,
                'status' => AssignmentSumInstance::STATUS_CORRECT,
            ]);
        }

        $poolMock = Mockery::mock(AssignmentPoolScore::class);
        $poolMock->shouldReceive('rebuildFromAttempts')
            ->once()
            ->andReturn([
                'pool' => 4,
                'attempted' => 4,
                'correct' => 4,
                'pending' => 0,
                'pending_remedial' => 0,
                'wrong' => 0,
                'completion_pct' => 100,
                'score_pct' => 100,
            ]);
        $poolMock->shouldReceive('metricsForAssignment')->andReturn([
            'pool' => 4,
            'attempted' => 4,
            'correct' => 4,
            'pending' => 0,
            'pending_remedial' => 0,
            'wrong' => 0,
            'completion_pct' => 100,
            'score_pct' => 100,
        ]);
        $this->app->instance(AssignmentPoolScore::class, $poolMock);

        $metrics = app(StudyPlanMetricsCacheService::class)->metricsForAssignmentRead($assignment->fresh());

        $this->assertSame(100, $metrics['completion_pct']);
        $this->assertSame(0, $metrics['pending']);
    }

    public function test_cache_assignment_metrics_only_writes_columns(): void
    {
        [$enrollment, , $practiceOne] = $this->seedMinimalChapterAssignment();

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $practiceOne->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        app(StudyPlanMetricsCacheService::class)->cacheAssignmentMetricsOnly($assignment, [
            'pool' => 5,
            'attempted' => 3,
            'correct' => 2,
            'pending' => 2,
            'pending_remedial' => 0,
            'wrong' => 0,
            'completion_pct' => 60,
            'score_pct' => 67,
        ]);

        $assignment->refresh();

        $this->assertSame(60, $assignment->cached_pool_metrics['completion_pct']);
        $this->assertNotNull($assignment->cached_metrics_at);
    }

    /**
     * @return array{0: \App\Models\StudentEnrollment, 1: \App\Models\SyllabusChapter, 2: \App\Models\Worksheet}
     */
    private function seedMinimalChapterAssignment(): array
    {
        $year = \App\Models\AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = \App\Models\Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = \App\Models\GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);
        $maths = \App\Models\Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths']);
        $version = \App\Models\SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'subject_id' => $maths->id,
        ]);
        $chapter = \App\Models\SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => '1',
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
        $topic = \App\Models\SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Basics',
            'sort_order' => 1,
        ]);
        $user = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_STUDENT]);
        $student = \App\Models\Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Metrics Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);
        $enrollment = \App\Models\StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => \App\Models\StudentEnrollment::STATUS_ACTIVE,
        ]);
        $worksheet = \App\Models\Worksheet::query()->create([
            'title' => 'Practice 1',
            'syllabus_topic_id' => $topic->id,
            'scope' => \App\Support\PracticeSetScope::TOPIC,
            'set_number' => 1,
            'set_code' => 'P1',
            'tier' => \App\Support\PracticeSetTier::STARTER,
            'status' => \App\Models\Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => \App\Support\WorksheetDeliveryMode::ONLINE,
        ]);

        return [$enrollment, $chapter, $worksheet];
    }
}
