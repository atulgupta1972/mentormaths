<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
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
use App\Models\User;
use App\Models\Worksheet;
use App\Support\AssignmentProgress;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAttemptResumeAndRedoTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_progress_counts_saved_answers_for_in_progress_test(): void
    {
        [$assignment, $questions] = $this->seedChapterTestAssignment(questionCount: 5);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);

        foreach ($questions->take(3) as $question) {
            $option = $question->options->firstWhere('is_correct', true);
            SetAttemptAnswer::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'question_option_id' => $option->id,
                'is_correct' => false,
            ]);
        }

        $partial = AssignmentProgress::partialProgress($assignment->fresh());

        $this->assertSame(3, $partial['done']);
        $this->assertSame(5, $partial['total']);
        $this->assertSame(2, $partial['remaining']);
        $this->assertSame('3/5', $partial['label']);
    }

    public function test_student_can_redo_completed_assignment_and_latest_score_is_shown_with_previous(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);

        [$assignment, $questions, $user] = $this->seedChapterTestAssignment(questionCount: 2, withUser: true);

        SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
            'score' => 1,
            'max_score' => 2,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);
        $assignment->update(['status' => SetAssignment::STATUS_COMPLETED]);

        $this->actingAs($user)
            ->post(route('student.assignments.redo', $assignment))
            ->assertRedirect(route('student.assignments.show', $assignment))
            ->assertSessionHas('success');

        $assignment->refresh();
        $this->assertSame(SetAssignment::STATUS_ASSIGNED, $assignment->status);
        $this->assertNotNull($assignment->reassigned_at);

        SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 2,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'completed_at' => now(),
            'score' => 2,
            'max_score' => 2,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);
        $assignment->update(['status' => SetAssignment::STATUS_COMPLETED]);

        $this->assertSame(2, SetAttempt::query()->where('set_assignment_id', $assignment->id)->count());

        $latest = SetAttempt::query()
            ->where('set_assignment_id', $assignment->id)
            ->orderByDesc('id')
            ->first();
        $this->assertSame(2, (int) $latest->score);

        $summary = AssignmentProgress::formatStudentDashboardSummary($assignment->fresh(), $latest);

        $this->assertSame('100% (2/2)', $summary['latest_score_label']);
        $this->assertSame('50% (1/2)', $summary['previous_score_label']);
        $this->assertSame('100% (2/2) (redo · was 50% (1/2))', $summary['score_display']);
        $this->assertTrue($summary['can_redo']);
        $this->assertSame(2, $summary['submitted_attempt_count']);
    }

    /**
     * @return array{0: SetAssignment, 1: \Illuminate\Support\Collection<int, Question>, 2?: User}
     */
    private function seedChapterTestAssignment(int $questionCount = 5, bool $withUser = false): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 6', 'sort_order' => 6, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Numbers',
            'chapter_number' => '1',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Place value',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'name' => 'Test Student',
            'email' => $studentUser->email,
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Chapter test',
            'syllabus_chapter_id' => $chapter->id,
            'syllabus_topic_id' => $topic->id,
            'scope' => PracticeSetScope::CHAPTER,
            'tier' => PracticeSetTier::STARTER,
            'set_number' => 1,
            'set_code' => 'T1',
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $questions = collect();
        for ($i = 1; $i <= $questionCount; $i++) {
            $question = Question::query()->create([
                'type' => Question::TYPE_MCQ,
                'syllabus_topic_id' => $topic->id,
                'question_text' => "Q{$i}?",
                'difficulty' => 'easy',
                'created_by' => $admin->id,
            ]);
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'option_text' => 'A',
                'is_correct' => true,
                'sort_order' => 0,
            ]);
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'option_text' => 'B',
                'is_correct' => false,
                'sort_order' => 1,
            ]);
            $worksheet->questions()->attach($question->id, ['sort_order' => $i - 1]);
            $questions->push($question->load('options'));
        }

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        if ($withUser) {
            return [$assignment, $questions, $studentUser];
        }

        return [$assignment, $questions];
    }
}
