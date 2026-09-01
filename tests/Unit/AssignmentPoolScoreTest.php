<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\AssignmentSumInstance;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\AssignmentPoolScore;
use App\Services\RevisionAssignmentService;
use App\Support\AssignmentProgress;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssignmentPoolScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_walkthrough_total_pool_completion_and_score(): void
    {
        [$assignment, $questions, $correctByQuestion, $wrongByQuestion] = $this->seedAssignmentWithQuestions(20);
        $pool = app(AssignmentPoolScore::class);

        $pool->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));

        $this->assertSame(20, AssignmentSumInstance::query()->count());
        $metrics = $pool->metricsForAssignment($assignment);
        $this->assertSame(20, $metrics['pool']);
        $this->assertSame(0, $metrics['attempted']);
        $this->assertSame(0, $metrics['completion_pct']);
        $this->assertSame(0, $metrics['score_pct']);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
            'score' => 15,
            'max_score' => 20,
        ]);

        foreach ($questions as $index => $question) {
            $isCorrect = $index < 15;
            SetAttemptAnswer::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_id' => $isCorrect
                    ? $correctByQuestion[$question->id]
                    : $wrongByQuestion[$question->id],
                'is_correct' => $isCorrect,
            ]);
        }

        $pool->syncFromBatchAttempt($attempt->fresh(['answers', 'assignment.enrollment', 'assignment.practiceSet.questions']));

        $metrics = $pool->metricsForAssignment($assignment);
        $this->assertSame(25, $metrics['pool']);
        $this->assertSame(20, $metrics['attempted']);
        $this->assertSame(15, $metrics['correct']);
        $this->assertSame(5, $metrics['pending']);
        $this->assertSame(5, $metrics['wrong']);
        $this->assertSame(80, $metrics['completion_pct']);
        $this->assertSame(75, $metrics['score_pct']);

        $remedialAttempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 2,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
            'is_correction_practice' => true,
            'score' => 5,
            'max_score' => 5,
        ]);

        $wrongQuestions = array_slice($questions, 15);
        foreach ($wrongQuestions as $question) {
            SetAttemptAnswer::query()->create([
                'set_attempt_id' => $remedialAttempt->id,
                'question_id' => $question->id,
                'selected_option_id' => $correctByQuestion[$question->id],
                'is_correct' => true,
            ]);
        }

        $pool->syncFromBatchAttempt($remedialAttempt->fresh([
            'answers',
            'assignment.enrollment',
            'assignment.practiceSet.questions',
        ]));

        $metrics = $pool->metricsForAssignment($assignment);
        $this->assertSame(25, $metrics['pool']);
        $this->assertSame(25, $metrics['attempted']);
        $this->assertSame(20, $metrics['correct']);
        $this->assertSame(0, $metrics['pending']);
        $this->assertSame(5, $metrics['wrong']);
        $this->assertSame(100, $metrics['completion_pct']);
        $this->assertSame(80, $metrics['score_pct']);

        // Fully corrected (even though score is 80%) → revision unlocks.
        $this->assertTrue(app(AssignmentPoolScore::class)->isFullyCorrected($assignment));
        $revision = app(RevisionAssignmentService::class)
            ->ensureFirstRevisionIfReady($assignment->fresh());
        $this->assertNotNull($revision);
        $this->assertSame(1, (int) $revision->revision_number);

        // Original wrongs stay wrong forever (never retroactively fixed).
        $this->assertSame(
            5,
            AssignmentSumInstance::query()
                ->where('set_assignment_id', $assignment->id)
                ->whereNull('source_instance_id')
                ->where('status', AssignmentSumInstance::STATUS_WRONG)
                ->count(),
        );
    }

    public function test_wrong_remedial_spawns_another_generation(): void
    {
        [$assignment, $questions, $correctByQuestion, $wrongByQuestion] = $this->seedAssignmentWithQuestions(1);
        $pool = app(AssignmentPoolScore::class);
        $pool->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));
        $question = $questions[0];

        $first = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
        ]);
        SetAttemptAnswer::query()->create([
            'set_attempt_id' => $first->id,
            'question_id' => $question->id,
            'selected_option_id' => $wrongByQuestion[$question->id],
            'is_correct' => false,
        ]);
        $pool->syncFromBatchAttempt($first->fresh(['answers', 'assignment.enrollment', 'assignment.practiceSet.questions']));

        $second = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 2,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
            'is_correction_practice' => true,
        ]);
        SetAttemptAnswer::query()->create([
            'set_attempt_id' => $second->id,
            'question_id' => $question->id,
            'selected_option_id' => $wrongByQuestion[$question->id],
            'is_correct' => false,
        ]);
        $pool->syncFromBatchAttempt($second->fresh(['answers', 'assignment.enrollment', 'assignment.practiceSet.questions']));

        $metrics = $pool->metricsForAssignment($assignment);
        $this->assertSame(3, $metrics['pool']);
        $this->assertSame(2, $metrics['attempted']);
        $this->assertSame(0, $metrics['correct']);
        $this->assertSame(1, $metrics['pending']);
        $this->assertSame(67, $metrics['completion_pct']);
        $this->assertSame(0, $metrics['score_pct']);
        $this->assertSame(
            1,
            AssignmentSumInstance::query()->where('generation', 2)->where('status', 'pending')->count(),
        );
    }

    public function test_submit_persists_pool_metrics_snapshot_on_assignment(): void
    {
        [$assignment, $questions, $correctByQuestion, $wrongByQuestion] = $this->seedAssignmentWithQuestions(4);
        $pool = app(AssignmentPoolScore::class);
        $pool->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
            'score' => 3,
            'max_score' => 4,
        ]);

        foreach ($questions as $index => $question) {
            SetAttemptAnswer::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_id' => $index < 3
                    ? $correctByQuestion[$question->id]
                    : $wrongByQuestion[$question->id],
                'is_correct' => $index < 3,
            ]);
        }

        $pool->syncFromBatchAttempt($attempt->fresh([
            'answers',
            'assignment.enrollment',
            'assignment.practiceSet.questions',
        ]));

        $assignment->refresh();
        $this->assertNotNull($assignment->pool_metrics_updated_at);
        $this->assertSame(5, $assignment->pool_metrics['pool']);
        $this->assertSame(4, $assignment->pool_metrics['attempted']);
        $this->assertSame(3, $assignment->pool_metrics['correct']);
        $this->assertSame(75, $assignment->pool_metrics['score_pct']);
        $this->assertSame(80, $assignment->pool_metrics['completion_pct']);
    }

    public function test_dashboard_summary_reads_saved_metrics_without_rebuilding_pool(): void
    {
        [$assignment, $questions, $correctByQuestion, $wrongByQuestion] = $this->seedAssignmentWithQuestions(4);
        $pool = app(AssignmentPoolScore::class);
        $pool->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
            'score' => 3,
            'max_score' => 4,
        ]);

        foreach ($questions as $index => $question) {
            SetAttemptAnswer::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_id' => $index < 3
                    ? $correctByQuestion[$question->id]
                    : $wrongByQuestion[$question->id],
                'is_correct' => $index < 3,
            ]);
        }

        $pool->syncFromBatchAttempt($attempt->fresh([
            'answers',
            'assignment.enrollment',
            'assignment.practiceSet.questions',
        ]));

        $instanceIds = AssignmentSumInstance::query()->orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($instanceIds);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $summary = AssignmentProgress::formatStudentDashboardSummary(
            $assignment->fresh(['practiceSet', 'attempts']),
            $attempt->fresh(),
        );

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $this->assertSame($instanceIds, AssignmentSumInstance::query()->orderBy('id')->pluck('id')->all());
        $this->assertStringNotContainsString('delete from', strtolower($queries));
        $this->assertSame(5, $summary['pool_metrics']['pool']);
        $this->assertSame(75, $summary['pool_metrics']['score_pct']);
        $this->assertSame(75, $summary['latest_score_percent']);
    }

    public function test_prime_metrics_fills_missing_snapshots_from_existing_rows(): void
    {
        [$assignment, $questions, $correctByQuestion] = $this->seedAssignmentWithQuestions(2);
        $pool = app(AssignmentPoolScore::class);
        $pool->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
        ]);
        foreach ($questions as $question) {
            SetAttemptAnswer::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_id' => $correctByQuestion[$question->id],
                'is_correct' => true,
            ]);
        }
        $pool->syncFromBatchAttempt($attempt->fresh([
            'answers',
            'assignment.enrollment',
            'assignment.practiceSet.questions',
        ]));

        $assignment->forceFill([
            'pool_metrics' => null,
            'pool_metrics_updated_at' => null,
        ])->save();

        $pool->primeMetricsForAssignments(collect([$assignment->fresh()]));

        $assignment->refresh();
        $this->assertNotNull($assignment->pool_metrics_updated_at);
        $this->assertSame(2, $assignment->pool_metrics['pool']);
        $this->assertSame(100, $assignment->pool_metrics['score_pct']);
    }

    public function test_refresh_command_writes_snapshots_from_existing_pool_rows(): void
    {
        [$assignment, $questions, $correctByQuestion] = $this->seedAssignmentWithQuestions(2);
        $pool = app(AssignmentPoolScore::class);
        $pool->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'completed_at' => now(),
        ]);
        foreach ($questions as $question) {
            SetAttemptAnswer::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'selected_option_id' => $correctByQuestion[$question->id],
                'is_correct' => true,
            ]);
        }
        $pool->syncFromBatchAttempt($attempt->fresh([
            'answers',
            'assignment.enrollment',
            'assignment.practiceSet.questions',
        ]));

        $assignment->forceFill([
            'pool_metrics' => null,
            'pool_metrics_updated_at' => null,
        ])->save();

        $this->artisan('assignments:refresh-pool-metrics')->assertSuccessful();

        $assignment->refresh();
        $this->assertNotNull($assignment->pool_metrics_updated_at);
        $this->assertSame(2, $assignment->pool_metrics['pool']);
        $this->assertSame(100, $assignment->pool_metrics['score_pct']);
    }

    /**
     * @return array{0: SetAssignment, 1: list<Question>, 2: array<int, int>, 3: array<int, int>}
     */
    private function seedAssignmentWithQuestions(int $count): array
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
            'name' => 'Pool Student',
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
            'title' => 'Pool set',
            'set_number' => 1,
            'set_code' => 'POOL1',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $questions = [];
        $correctByQuestion = [];
        $wrongByQuestion = [];

        for ($i = 1; $i <= $count; $i++) {
            $question = Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'question_text' => "Q{$i}",
                'type' => Question::TYPE_MCQ,
                'source' => Question::SOURCE_MANUAL,
            ]);
            $wrong = QuestionOption::query()->create([
                'question_id' => $question->id,
                'option_text' => 'wrong',
                'is_correct' => false,
                'sort_order' => 1,
            ]);
            $correct = QuestionOption::query()->create([
                'question_id' => $question->id,
                'option_text' => 'right',
                'is_correct' => true,
                'sort_order' => 2,
            ]);
            $worksheet->questions()->attach($question->id, ['sort_order' => $i]);
            $questions[] = $question;
            $correctByQuestion[$question->id] = $correct->id;
            $wrongByQuestion[$question->id] = $wrong->id;
        }

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_IN_PROGRESS,
        ]);

        return [$assignment, $questions, $correctByQuestion, $wrongByQuestion];
    }
}
