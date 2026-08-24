<?php

namespace Tests\Unit;

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
use App\Models\Worksheet;
use App\Services\SetAttemptService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetAttemptDraftAnswerTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_answer_persists_and_reloads_without_grading(): void
    {
        [$attempt, $question, $option] = $this->seedBatchAttempt();

        $service = app(SetAttemptService::class);
        $payload = $service->saveDraftAnswer($attempt, (int) $question->id, (int) $option->id);

        $this->assertSame(1, $payload['answered']);
        $this->assertSame([(int) $question->id => (int) $option->id], $payload['answers']);

        $row = SetAttemptAnswer::query()
            ->where('set_attempt_id', $attempt->id)
            ->where('question_id', $question->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame((int) $option->id, (int) $row->question_option_id);
        $this->assertFalse($row->is_correct);
        $this->assertSame(1, (int) $attempt->fresh()->current_question_index);

        $map = $service->draftAnswersMap($attempt->fresh(['answers']));
        $this->assertSame([(int) $question->id => (int) $option->id], $map);
    }

    public function test_clearing_draft_removes_answer_row(): void
    {
        [$attempt, $question, $option] = $this->seedBatchAttempt();

        $service = app(SetAttemptService::class);
        $service->saveDraftAnswer($attempt, (int) $question->id, (int) $option->id);
        $service->saveDraftAnswer($attempt->fresh(), (int) $question->id, null);

        $this->assertSame(0, SetAttemptAnswer::query()->where('set_attempt_id', $attempt->id)->count());
        $this->assertSame([], $service->draftAnswersMap($attempt->fresh(['answers'])));
    }

    /**
     * @return array{0: SetAttempt, 1: Question, 2: QuestionOption}
     */
    private function seedBatchAttempt(): array
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

        $subject = Subject::query()->create([
            'code' => 'MATHS',
            'name' => 'Mathematics',
        ]);

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
            'title' => 'Chapter test',
            'set_number' => 1,
            'set_code' => 'T701',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => '2 + 2 = ?',
            'difficulty' => 'easy',
            'type' => Question::TYPE_MCQ,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 0]);

        $option = QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '4',
            'is_correct' => true,
            'sort_order' => 0,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '5',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'status' => SetAssignment::STATUS_IN_PROGRESS,
            'assigned_at' => now(),
        ]);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        return [$attempt, $question, $option];
    }
}
