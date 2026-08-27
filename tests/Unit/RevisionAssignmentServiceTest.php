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
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevisionAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_spawns_revision_one_when_original_score_hits_100(): void
    {
        [$assignment, $questions, $correctByQuestion] = $this->seedAssignment(3);
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
            'max_score' => 3,
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

        $revision = app(RevisionAssignmentService::class)->ensureFirstRevisionIfReady($assignment->fresh());

        $this->assertNotNull($revision);
        $this->assertSame(1, (int) $revision->revision_number);
        $this->assertSame($assignment->id, (int) $revision->parent_assignment_id);
        $this->assertSame(SetAssignment::STATUS_ASSIGNED, $revision->status);
        $this->assertSame(now()->toDateString(), $revision->due_date?->toDateString());
        $this->assertSame(3, AssignmentSumInstance::query()->where('set_assignment_id', $revision->id)->count());
    }

    public function test_does_not_spawn_revision_until_score_is_100(): void
    {
        [$assignment, $questions, $correctByQuestion, $wrongByQuestion] = $this->seedAssignment(2);
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

        SetAttemptAnswer::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $questions[0]->id,
            'selected_option_id' => $correctByQuestion[$questions[0]->id],
            'is_correct' => true,
        ]);
        SetAttemptAnswer::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $questions[1]->id,
            'selected_option_id' => $wrongByQuestion[$questions[1]->id],
            'is_correct' => false,
        ]);

        $pool->syncFromBatchAttempt($attempt->fresh([
            'answers',
            'assignment.enrollment',
            'assignment.practiceSet.questions',
        ]));

        $revision = app(RevisionAssignmentService::class)->ensureFirstRevisionIfReady($assignment->fresh());

        $this->assertNull($revision);
        $this->assertSame(
            0,
            SetAssignment::query()->where('revision_number', '>', 0)->count(),
        );
    }

    public function test_start_next_revision_creates_revision_two(): void
    {
        [$assignment, $questions, $correctByQuestion] = $this->seedAssignment(1);
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
        SetAttemptAnswer::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $questions[0]->id,
            'selected_option_id' => $correctByQuestion[$questions[0]->id],
            'is_correct' => true,
        ]);
        $pool->syncFromBatchAttempt($attempt->fresh([
            'answers',
            'assignment.enrollment',
            'assignment.practiceSet.questions',
        ]));

        $service = app(RevisionAssignmentService::class);
        $rev1 = $service->ensureFirstRevisionIfReady($assignment->fresh());
        $this->assertNotNull($rev1);

        $rev1->update(['status' => SetAssignment::STATUS_COMPLETED]);

        $rev2 = $service->startNextRevision($rev1);
        $this->assertSame(2, (int) $rev2->revision_number);
        $this->assertSame($assignment->id, (int) $rev2->parent_assignment_id);
    }

    /**
     * @return array{0: SetAssignment, 1: list<\App\Models\Question>, 2: array<int, int>, 3?: array<int, int>}
     */
    private function seedAssignment(int $count): array
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
            'name' => 'Rev Student',
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
            'title' => 'Rev set',
            'set_number' => 1,
            'set_code' => 'REV1',
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
            'revision_number' => 0,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_IN_PROGRESS,
        ]);

        return [$assignment, $questions, $correctByQuestion, $wrongByQuestion];
    }
}
