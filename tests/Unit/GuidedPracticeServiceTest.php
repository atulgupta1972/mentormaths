<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\GuidedAttemptQuestion;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
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
use App\Services\GuidedPracticeService;
use App\Services\SetAttemptService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidedPracticeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_first_try_is_stored_for_review(): void
    {
        [$attempt, $wrongOption, $correctOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $service->submitAnswer($attempt, $wrongOption->id);
        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $correctOption->id);

        $guided = $attempt->fresh()->guidedQuestions->first();

        $this->assertSame($wrongOption->id, $guided->first_wrong_option_id);
        $this->assertSame($correctOption->id, $guided->final_option_id);
        $this->assertTrue($guided->final_is_correct);
    }

    public function test_student_review_shows_wrong_then_correct_attempts(): void
    {
        [$attempt, $wrongOption, $correctOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $service->submitAnswer($attempt, $wrongOption->id);
        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $correctOption->id);

        $attempt = $attempt->fresh([
            'guidedQuestions.question.options',
            'assignment.practiceSet.questions.options',
        ]);

        $review = \App\Support\AttemptResultSummary::forStudentReview($attempt);
        $question = $review['questions'][0];

        $this->assertSame('1st try — wrong', $question['attempts'][0]['label']);
        $this->assertFalse($question['attempts'][0]['is_correct']);
        $this->assertSame('2nd try — correct', $question['attempts'][1]['label']);
        $this->assertTrue($question['attempts'][1]['is_correct']);
    }

    public function test_early_hint_shows_method_and_blocks_first_try_score(): void
    {
        [$attempt, , $correctOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $payload = $service->requestEarlyHint($attempt->fresh(['guidedQuestions', 'assignment']));

        $this->assertSame('explained', $payload['phase']);
        $this->assertTrue($payload['show_explanation']);
        $this->assertNotEmpty($payload['question']['method_hint']);

        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $correctOption->id);
        $guided = $attempt->fresh()->guidedQuestions->first();

        $this->assertTrue($guided->used_early_hint);
        $this->assertTrue($guided->corrected_after_help);
        $this->assertFalse($guided->first_try_correct);
    }

    public function test_wrong_twice_does_not_show_method_until_hint_requested(): void
    {
        [$attempt, $wrongOption, $correctOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);

        $service->submitAnswer($attempt, $wrongOption->id);
        $attempt->refresh();
        $payload = $service->submitAnswer($attempt, $wrongOption->id);

        $this->assertSame('retry', $payload['phase']);
        $this->assertFalse($payload['show_explanation']);
        $this->assertTrue($payload['can_show_hint']);
        $this->assertNull($payload['question']['method_hint']);

        $service->requestEarlyHint($attempt->fresh(['guidedQuestions', 'assignment']));
        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $correctOption->id);
        $attempt->refresh();

        $guided = $attempt->guidedQuestions->first();
        $this->assertTrue($guided->corrected_after_help);
        $this->assertSame(GuidedAttemptQuestion::PHASE_DONE, $guided->phase);
    }

    public function test_give_up_queues_resolution_item(): void
    {
        [$attempt, $wrongOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $service->submitAnswer($attempt, $wrongOption->id);
        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $wrongOption->id);

        $service->giveUp($attempt->fresh(['guidedQuestions', 'assignment']));

        $this->assertDatabaseHas('question_resolution_items', [
            'status' => 'pending',
        ]);
    }

    public function test_give_up_from_first_question_queues_resolution_item(): void
    {
        [$attempt] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $payload = $service->giveUp($attempt->fresh(['guidedQuestions', 'assignment']));

        $this->assertTrue($payload['help_requested']);
        $this->assertDatabaseHas('question_resolution_items', [
            'status' => 'pending',
        ]);
    }

    public function test_initialize_expands_guided_queue_when_worksheet_grows(): void
    {
        [$attempt] = $this->seedGuidedAttempt();
        $worksheet = $attempt->assignment->practiceSet;

        $extra = Question::query()->create([
            'syllabus_topic_id' => $worksheet->questions()->first()->syllabus_topic_id,
            'question_text' => 'What is 3 + 3?',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);
        QuestionOption::query()->create([
            'question_id' => $extra->id,
            'option_text' => '6',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        $worksheet->questions()->attach($extra->id, ['sort_order' => 2]);

        $this->assertSame(1, $attempt->guidedQuestions()->count());

        app(GuidedPracticeService::class)->initialize($attempt->fresh(['guidedQuestions', 'assignment.practiceSet']));

        $this->assertSame(2, $attempt->fresh()->guidedQuestions()->count());
        $this->assertSame(
            [$worksheet->questions()->orderBy('worksheet_question.sort_order')->pluck('questions.id')->all()],
            [$attempt->fresh()->guidedQuestions()->orderBy('sort_order')->pluck('question_id')->all()],
        );
    }

    public function test_ensure_attempt_ready_expands_stale_guided_queue(): void
    {
        [$attempt] = $this->seedGuidedAttempt();
        $worksheet = $attempt->assignment->practiceSet;
        $topicId = $worksheet->questions()->first()->syllabus_topic_id;

        $extra = Question::query()->create([
            'syllabus_topic_id' => $topicId,
            'question_text' => 'What is 5 + 5?',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);
        QuestionOption::query()->create([
            'question_id' => $extra->id,
            'option_text' => '10',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        $worksheet->questions()->attach($extra->id, ['sort_order' => 2]);

        app(GuidedPracticeService::class)->ensureAttemptReady($attempt->fresh(['guidedQuestions', 'assignment.practiceSet']));

        $this->assertSame(2, $attempt->fresh()->guidedQuestions()->count());
    }

    public function test_report_issue_skips_marks_and_queues_admin_report(): void
    {
        [$attempt] = $this->seedGuidedAttempt();
        $questionId = $attempt->guidedQuestions->first()->question_id;

        $guided = app(GuidedPracticeService::class);
        $payload = app(\App\Services\QuestionIssueReportService::class)
            ->reportFromGuided($attempt->fresh(['guidedQuestions', 'assignment.enrollment.student']), $guided);

        $this->assertTrue($payload['issue_reported']);
        $this->assertDatabaseHas('question_issue_reports', [
            'question_id' => $questionId,
            'status' => 'pending_admin',
            'context' => 'guided',
        ]);

        $row = $attempt->fresh()->guidedQuestions->first();
        $this->assertTrue($row->reported_issue);
        $this->assertSame(GuidedAttemptQuestion::PHASE_REPORTED_ISSUE, $row->phase);

        $this->assertDatabaseMissing('practice_correction_items', [
            'question_id' => $questionId,
            'status' => 'pending',
        ]);

        $attempt = $attempt->fresh();
        $this->assertSame(\App\Models\SetAttempt::STATUS_SUBMITTED, $attempt->status);
        $this->assertSame(0, (int) $attempt->max_score);
        $this->assertSame(0, (int) $attempt->score);
    }

    public function test_admin_mark_fixed_returns_sum_to_correction_queue(): void
    {
        [$attempt] = $this->seedGuidedAttempt();
        $studentId = $attempt->assignment->enrollment->student_id;
        $guided = app(GuidedPracticeService::class);
        app(\App\Services\QuestionIssueReportService::class)
            ->reportFromGuided($attempt->fresh(['guidedQuestions', 'assignment.enrollment.student']), $guided);

        $report = \App\Models\QuestionIssueReport::query()->firstOrFail();
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        app(\App\Services\QuestionIssueReportService::class)
            ->markFixedAndReturnToStudent($report, $admin);

        $this->assertSame('awaiting_reattempt', $report->fresh()->status);
        $this->assertDatabaseHas('practice_correction_items', [
            'student_id' => $studentId,
            'question_id' => $report->question_id,
            'status' => 'pending',
            'failure_reason' => 'content_fixed',
        ]);
    }

    public function test_admin_confirm_question_correct_forfeits_score_and_queues_reattempt(): void
    {
        [$attempt] = $this->seedGuidedAttempt();
        $studentId = $attempt->assignment->enrollment->student_id;
        $guided = app(GuidedPracticeService::class);
        app(\App\Services\QuestionIssueReportService::class)
            ->reportFromGuided($attempt->fresh(['guidedQuestions', 'assignment.enrollment.student']), $guided);

        $report = \App\Models\QuestionIssueReport::query()->firstOrFail();
        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        $student = \App\Models\Student::query()->findOrFail($studentId);
        $student->update(['email' => 'student-reattempt@example.com']);

        \Illuminate\Support\Facades\Mail::fake();

        app(\App\Services\QuestionIssueReportService::class)
            ->confirmQuestionCorrectRequireReattempt($report, $admin);

        $report = $report->fresh();
        $this->assertSame('awaiting_reattempt', $report->status);
        $this->assertTrue((bool) $report->score_forfeited);
        $this->assertSame('question_correct', $report->reason);

        $row = $attempt->fresh()->guidedQuestions->first();
        $this->assertFalse((bool) $row->reported_issue);
        $this->assertFalse((bool) $row->final_is_correct);

        $attempt = $attempt->fresh();
        $this->assertSame(0, (int) $attempt->score);
        $this->assertSame(1, (int) $attempt->max_score);

        $this->assertDatabaseHas('practice_correction_items', [
            'student_id' => $studentId,
            'question_id' => $report->question_id,
            'status' => 'pending',
            'failure_reason' => 'question_correct',
        ]);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\QuestionCorrectReattempt::class);
    }

    public function test_admin_payload_includes_check_and_uploader_fields(): void
    {
        [$attempt] = $this->seedGuidedAttempt();
        $guided = app(GuidedPracticeService::class);
        app(\App\Services\QuestionIssueReportService::class)
            ->reportFromGuided($attempt->fresh(['guidedQuestions', 'assignment.enrollment.student']), $guided);

        $studentId = $attempt->assignment->enrollment->student_id;
        $payload = app(\App\Services\QuestionIssueReportService::class)->pendingForStudent($studentId);

        $this->assertCount(1, $payload);
        $this->assertArrayHasKey('check_url', $payload[0]);
        $this->assertArrayHasKey('edit_url', $payload[0]);
        $this->assertArrayHasKey('can_return_to_uploader', $payload[0]);
        $this->assertNotEmpty($payload[0]['question_text']);
    }

    public function test_fill_in_blank_wrong_twice_hides_method_until_hint_requested(): void
    {
        [$attempt] = $this->seedFillBlankGuidedAttempt();

        $service = app(GuidedPracticeService::class);

        $service->submitAnswer($attempt, null, '-3');
        $payload = $service->submitAnswer($attempt->fresh(['guidedQuestions.question.blankAnswer']), null, '-3');

        $this->assertSame('retry', $payload['phase']);
        $this->assertFalse($payload['show_explanation']);
        $this->assertTrue($payload['can_show_hint']);
        $this->assertSame('fill_in_blank', $payload['question']['type']);
        $this->assertNull($payload['question']['method_hint']);
    }

    public function test_fill_in_blank_correct_on_first_try(): void
    {
        [$attempt] = $this->seedFillBlankGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $payload = $service->submitAnswer($attempt, null, '-4');

        $this->assertSame('correct', $payload['feedback']['type']);
        $this->assertTrue($attempt->fresh()->guidedQuestions->first()->first_try_correct);
    }

    public function test_repairs_one_based_sort_order_for_in_progress_attempt(): void
    {
        [$attempt] = $this->seedGuidedAttempt(withGuidedInit: false);

        foreach ($attempt->assignment->practiceSet->questions as $index => $question) {
            GuidedAttemptQuestion::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'sort_order' => $index + 1,
                'phase' => GuidedAttemptQuestion::PHASE_PENDING,
            ]);
        }

        $attempt->update([
            'mode' => SetAttempt::MODE_GUIDED,
            'current_question_index' => 0,
        ]);

        $service = app(GuidedPracticeService::class);
        $service->ensureAttemptReady($attempt->fresh());

        $attempt->refresh()->load('guidedQuestions');

        $this->assertSame(0, $attempt->current_question_index);
        $this->assertSame(
            GuidedAttemptQuestion::PHASE_ANSWERING,
            $attempt->guidedQuestions->firstWhere('sort_order', 0)?->phase,
        );

        $payload = $service->buildPayload($attempt->fresh(['guidedQuestions.question.options']));
        $this->assertFalse($payload['finished']);
        $this->assertNotNull($payload['question']);
    }

    public function test_build_payload_repairs_stale_current_question_index(): void
    {
        [$attempt] = $this->seedGuidedAttempt(withGuidedInit: false);

        GuidedAttemptQuestion::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $attempt->assignment->practiceSet->questions()->first()->id,
            'sort_order' => 0,
            'phase' => GuidedAttemptQuestion::PHASE_ANSWERING,
        ]);

        $attempt->update([
            'mode' => SetAttempt::MODE_GUIDED,
            'current_question_index' => 3,
        ]);

        $payload = app(GuidedPracticeService::class)->buildPayload($attempt->fresh(['guidedQuestions.question.options']));

        $this->assertFalse($payload['finished']);
        $this->assertSame(1, $payload['progress']['current']);
        $this->assertSame(0, $attempt->fresh()->current_question_index);
    }

    public function test_stale_batch_attempt_on_topic_practice_upgrades_to_guided(): void
    {
        [$attempt] = $this->seedGuidedAttempt(withGuidedInit: false);

        app(SetAttemptService::class)->ensureGuidedForTopicPractice($attempt);

        $attempt->refresh();
        $this->assertTrue($attempt->isGuided());
        $this->assertSame(1, $attempt->guidedQuestions()->count());
    }

    /**
     * @return array{0: SetAttempt, 1?: QuestionOption, 2?: QuestionOption}
     */
    private function seedGuidedAttempt(bool $withGuidedInit = true): array
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
            'title' => 'Starter set',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is 2 + 2?',
            'explanation' => 'Add the two numbers. Answer key: b.',
            'method_hint' => 'Add the two whole numbers together.',
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
        }

        return [$attempt, $wrongOption, $correctOption];
    }

    /**
     * @return array{0: SetAttempt}
     */
    private function seedFillBlankGuidedAttempt(): array
    {
        [$attempt, , ] = $this->seedGuidedAttempt(withGuidedInit: false);

        $attempt->update(['mode' => SetAttempt::MODE_GUIDED]);

        $assignment = $attempt->assignment()->with('practiceSet.questions')->first();
        $question = $assignment->practiceSet->questions->first();

        $question->update([
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => '(-12) + 8 = ____',
            'method_hint' => 'Subtract absolute values and keep the sign of the larger number.',
        ]);

        $question->options()->delete();

        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'answer_format' => QuestionBlankAnswer::FORMAT_INTEGER,
            'correct_answer' => '-4',
        ]);

        GuidedAttemptQuestion::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'sort_order' => 0,
            'phase' => GuidedAttemptQuestion::PHASE_ANSWERING,
        ]);

        return [$attempt->fresh(['guidedQuestions.question.blankAnswer'])];
    }
}
