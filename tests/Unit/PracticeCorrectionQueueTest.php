<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ExamPlan;
use App\Models\GradeLevel;
use App\Models\GuidedAttemptQuestion;
use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Models\User;
use App\Models\WrittenSubmission;
use App\Models\WrittenSubmissionItem;
use App\Services\GuidedPracticeService;
use App\Services\PracticeCorrectionQueueService;
use App\Services\SetAttemptService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeCorrectionQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_not_first_try_correct_queues_pending_item(): void
    {
        [$attempt, $wrongOption, $correctOption] = $this->seedGuidedAttempt();

        $guided = app(GuidedPracticeService::class);
        $guided->submitAnswer($attempt, $wrongOption->id);
        $guided->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $correctOption->id);

        $item = PracticeCorrectionItem::query()->first();

        $this->assertNotNull($item);
        $this->assertSame(PracticeCorrectionItem::STATUS_PENDING, $item->status);
        $this->assertSame(PracticeCorrectionItem::SOURCE_GUIDED_PRACTICE, $item->source_type);
        $this->assertSame('first_wrong', $item->failure_reason);
    }

    public function test_first_try_correct_does_not_queue_item(): void
    {
        [$attempt, , $correctOption] = $this->seedGuidedAttempt();

        app(GuidedPracticeService::class)->submitAnswer($attempt, $correctOption->id);

        $this->assertSame(0, PracticeCorrectionItem::query()->count());
    }

    public function test_first_try_correct_on_correction_attempt_clears_pending_item(): void
    {
        [$attempt, , $correctOption] = $this->seedGuidedAttempt();
        $questionId = (int) $attempt->guidedQuestions()->value('question_id');
        $studentId = (int) StudentEnrollment::query()
            ->whereKey($attempt->assignment()->value('student_enrollment_id'))
            ->value('student_id');

        PracticeCorrectionItem::query()->create([
            'student_id' => $studentId,
            'question_id' => $questionId,
            'source_type' => PracticeCorrectionItem::SOURCE_GUIDED_PRACTICE,
            'failure_reason' => 'first_wrong',
            'status' => PracticeCorrectionItem::STATUS_PENDING,
            'first_failure_at' => now()->subDay(),
        ]);

        $attempt->update(['is_correction_practice' => true]);

        app(GuidedPracticeService::class)->submitAnswer($attempt, $correctOption->id);

        $this->assertSame(
            0,
            PracticeCorrectionItem::query()->where('status', PracticeCorrectionItem::STATUS_PENDING)->count(),
        );
        $this->assertSame(1, PracticeCorrectionItem::query()->where('status', PracticeCorrectionItem::STATUS_CORRECTED)->count());
    }

    public function test_regular_redo_first_try_does_not_clear_pending_item(): void
    {
        [$attempt, , $correctOption] = $this->seedGuidedAttempt();
        $questionId = (int) $attempt->guidedQuestions()->value('question_id');
        $studentId = (int) StudentEnrollment::query()
            ->whereKey($attempt->assignment()->value('student_enrollment_id'))
            ->value('student_id');

        PracticeCorrectionItem::query()->create([
            'student_id' => $studentId,
            'question_id' => $questionId,
            'source_type' => PracticeCorrectionItem::SOURCE_GUIDED_PRACTICE,
            'failure_reason' => 'first_wrong',
            'status' => PracticeCorrectionItem::STATUS_PENDING,
            'first_failure_at' => now()->subDay(),
        ]);

        app(GuidedPracticeService::class)->submitAnswer($attempt, $correctOption->id);

        $this->assertSame(1, PracticeCorrectionItem::query()->where('status', PracticeCorrectionItem::STATUS_PENDING)->count());
    }

    public function test_give_up_queues_pending_item(): void
    {
        [$attempt, $wrongOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $service->submitAnswer($attempt, $wrongOption->id);
        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $wrongOption->id);
        $service->giveUp($attempt->fresh(['guidedQuestions', 'assignment']));

        $item = PracticeCorrectionItem::query()->first();

        $this->assertNotNull($item);
        $this->assertSame('gave_up', $item->failure_reason);
    }

    public function test_batch_wrong_answers_are_queued(): void
    {
        [$attempt, $wrongOption] = $this->seedGuidedAttempt(withGuidedInit: false);

        app(SetAttemptService::class)->submit($attempt, [
            $attempt->assignment->practiceSet->questions->first()->id => $wrongOption->id,
        ]);

        $item = PracticeCorrectionItem::query()->first();

        $this->assertNotNull($item);
        $this->assertSame(PracticeCorrectionItem::SOURCE_BATCH_TEST, $item->source_type);
        $this->assertSame('batch_wrong', $item->failure_reason);
    }

    public function test_written_wrong_items_are_queued(): void
    {
        [$assignment, $question, $student] = $this->seedWrittenAssignment();

        $submission = WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_GRADED,
            'score' => 0,
            'max_score' => 1,
            'uploaded_at' => now(),
            'graded_at' => now(),
        ]);

        WrittenSubmissionItem::query()->create([
            'written_submission_id' => $submission->id,
            'question_id' => $question->id,
            'question_number' => 1,
            'score' => 0,
            'max_score' => 1,
            'is_correct' => false,
        ]);

        app(PracticeCorrectionQueueService::class)->syncFromWrittenSubmission($submission);

        $item = PracticeCorrectionItem::query()->where('student_id', $student->id)->first();

        $this->assertNotNull($item);
        $this->assertSame(PracticeCorrectionItem::SOURCE_WRITTEN, $item->source_type);
    }

    public function test_re_wrong_after_correction_creates_new_pending_item(): void
    {
        [$attempt, $wrongOption, $correctOption] = $this->seedGuidedAttempt();
        $queue = app(PracticeCorrectionQueueService::class);
        $guided = app(GuidedPracticeService::class);

        $guided->submitAnswer($attempt, $wrongOption->id);
        $guided->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $correctOption->id);

        PracticeCorrectionItem::query()->first()?->update([
            'status' => PracticeCorrectionItem::STATUS_CORRECTED,
            'corrected_at' => now(),
            'corrected_in' => PracticeCorrectionItem::CORRECTED_IN_STUDY_PLAN,
        ]);

        $attempt2 = SetAttempt::query()->create([
            'set_assignment_id' => $attempt->set_assignment_id,
            'attempt_number' => 2,
            'mode' => SetAttempt::MODE_GUIDED,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        GuidedAttemptQuestion::query()->create([
            'set_attempt_id' => $attempt2->id,
            'question_id' => $wrongOption->question_id,
            'sort_order' => 0,
            'phase' => GuidedAttemptQuestion::PHASE_ANSWERING,
        ]);

        $guided->submitAnswer($attempt2, $wrongOption->id);
        $guided->submitAnswer($attempt2->fresh(['guidedQuestions.question.options']), $wrongOption->id);
        $guided->giveUp($attempt2->fresh(['guidedQuestions', 'assignment']));

        $this->assertSame(1, PracticeCorrectionItem::query()->where('status', PracticeCorrectionItem::STATUS_PENDING)->count());
        $this->assertSame(1, PracticeCorrectionItem::query()->where('status', PracticeCorrectionItem::STATUS_CORRECTED)->count());
    }

    public function test_daily_selection_prioritises_exam_chapter(): void
    {
        [$assignment, $question, $student, $chapter] = $this->seedWrittenAssignment();

        $otherChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $chapter->syllabus_version_id,
            'name' => 'Fractions',
            'chapter_number' => 'Ch 2',
            'sort_order' => 2,
        ]);

        PracticeCorrectionItem::query()->create([
            'student_id' => $student->id,
            'question_id' => $question->id,
            'syllabus_chapter_id' => $otherChapter->id,
            'worksheet_id' => $assignment->worksheet_id,
            'set_assignment_id' => $assignment->id,
            'source_type' => PracticeCorrectionItem::SOURCE_WRITTEN,
            'failure_reason' => 'written_wrong',
            'status' => PracticeCorrectionItem::STATUS_PENDING,
            'first_failure_at' => now()->subDay(),
        ]);

        $examQuestion = Question::query()->create([
            'syllabus_topic_id' => SyllabusTopic::query()->create([
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Exam topic',
                'sort_order' => 1,
            ])->id,
            'question_text' => 'Exam Q',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        PracticeCorrectionItem::query()->create([
            'student_id' => $student->id,
            'question_id' => $examQuestion->id,
            'syllabus_chapter_id' => $chapter->id,
            'worksheet_id' => $assignment->worksheet_id,
            'set_assignment_id' => $assignment->id,
            'source_type' => PracticeCorrectionItem::SOURCE_GUIDED_PRACTICE,
            'failure_reason' => 'first_wrong',
            'status' => PracticeCorrectionItem::STATUS_PENDING,
            'first_failure_at' => now(),
        ]);

        ExamPlan::query()->create([
            'student_enrollment_id' => $assignment->student_enrollment_id,
            'exam_date' => now()->addWeek()->toDateString(),
            'title' => 'Unit test',
            'exam_type' => ExamPlan::TYPE_UNIT_TEST,
            'status' => ExamPlan::STATUS_PLANNED,
            'created_by' => User::factory()->create()->id,
        ])->chapters()->attach($chapter->id);

        $selected = app(PracticeCorrectionQueueService::class)
            ->selectForDailyDrill($student, 1);

        $this->assertCount(1, $selected);
        $this->assertSame($examQuestion->id, $selected->first()->question_id);
    }

    /**
     * @return array{0: SetAttempt, 1: QuestionOption, 2: QuestionOption}
     */
    private function seedGuidedAttempt(bool $withGuidedInit = true, int $setNumber = 1): array
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
            'set_number' => $setNumber,
            'set_code' => 'S7'.$setNumber.'1',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is 2 + 2?',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        $wrongOption = QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '3',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $correctOption = QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '4',
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_IN_PROGRESS,
        ]);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => $withGuidedInit ? SetAttempt::MODE_GUIDED : SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        if ($withGuidedInit) {
            GuidedAttemptQuestion::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'sort_order' => 0,
                'phase' => GuidedAttemptQuestion::PHASE_ANSWERING,
            ]);
        } else {
            $attempt->setRelation('assignment', $assignment);
            $assignment->setRelation('practiceSet', $worksheet);
            $worksheet->setRelation('questions', collect([$question]));
        }

        return [$attempt, $wrongOption, $correctOption];
    }

    /**
     * @return array{0: SetAssignment, 1: Question, 2: Student, 3: SyllabusChapter}
     */
    private function seedWrittenAssignment(): array
    {
        [$attempt, , ] = $this->seedGuidedAttempt(withGuidedInit: false);
        $assignment = $attempt->assignment()->with(['practiceSet.questions', 'enrollment.student'])->first();
        $question = $assignment->practiceSet->questions->first();
        $chapter = SyllabusChapter::query()->firstOrFail();

        return [$assignment, $question, $assignment->enrollment->student, $chapter];
    }
}
