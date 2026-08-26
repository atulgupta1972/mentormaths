<?php

namespace Tests\Feature\ContentUploader;

use App\Mail\ContentTaskReturnedUploader;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentQuestionCorrection;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionIssueReport;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\QuestionIssueReportService;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MisprintAutoQueueAndEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_report_auto_queues_correction_for_uploader(): void
    {
        Mail::fake();

        [$uploader, $chapter, $task, $question] = $this->seedPublishedFillBlankWithUploader();
        [$student] = $this->seedStudent();

        $report = app(QuestionIssueReportService::class)->reportFromBatch(
            $this->seedInProgressBatchAttempt($student, $question),
            $question,
        );

        $report->refresh();
        $this->assertSame(QuestionIssueReport::STATUS_SENT_TO_UPLOADER, $report->status);

        $this->assertDatabaseHas('content_question_corrections', [
            'content_upload_task_id' => $task->id,
            'question_id' => $question->id,
            'status' => ContentQuestionCorrection::STATUS_PENDING,
            'source' => ContentQuestionCorrection::SOURCE_STUDENT_REPORT,
        ]);

        Mail::assertSent(ContentTaskReturnedUploader::class);
    }

    public function test_uploader_can_edit_fill_blank_correction_and_return_to_student(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$uploader, $chapter, $task, $question] = $this->seedPublishedFillBlankWithUploader();
        [$student] = $this->seedStudent();

        $this->assertTrue($uploader->fresh()->isContentUploader(), 'uploader should have content_uploader group');

        app(QuestionIssueReportService::class)->reportFromBatch(
            $this->seedInProgressBatchAttempt($student, $question),
            $question,
        );

        $correction = ContentQuestionCorrection::query()
            ->where('question_id', $question->id)
            ->where('status', ContentQuestionCorrection::STATUS_PENDING)
            ->firstOrFail();

        $this->assertSame((int) $uploader->id, (int) $correction->task->assigned_to_user_id);

        $this->actingAs($uploader)
            ->get(route('content.corrections.edit', $correction))
            ->assertOk();

        $this->actingAs($uploader)
            ->put(route('content.corrections.update', $correction), [
                'question_text' => 'In the figure, corresponding angles measure 76° and 76°. The common measure is ___°.',
                'answer_format' => 'integer',
                'correct_answer' => '76',
                'explanation' => 'Corresponding angles are equal.',
                'method_hint' => 'Look at the matching arms.',
            ])
            ->assertRedirect(route('content.tasks.index'));

        $this->assertSame('76', $question->fresh()->blankAnswer?->correct_answer);
        $this->assertSame(ContentQuestionCorrection::STATUS_COMPLETED, $correction->fresh()->status);
        $this->assertSame(
            QuestionIssueReport::STATUS_AWAITING_REATTEMPT,
            QuestionIssueReport::query()->where('question_id', $question->id)->value('status'),
        );
    }

    public function test_uploader_can_delete_irrelevant_question_from_correction(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$uploader, $chapter, $task, $question] = $this->seedPublishedFillBlankWithUploader();
        [$student] = $this->seedStudent();

        app(QuestionIssueReportService::class)->reportFromBatch(
            $this->seedInProgressBatchAttempt($student, $question),
            $question,
        );

        $correction = ContentQuestionCorrection::query()
            ->where('question_id', $question->id)
            ->where('status', ContentQuestionCorrection::STATUS_PENDING)
            ->firstOrFail();

        $questionId = $question->id;

        $this->actingAs($uploader)
            ->delete(route('content.corrections.destroy', $correction))
            ->assertRedirect(route('content.tasks.index'));

        $this->assertDatabaseMissing('questions', ['id' => $questionId]);
        $this->assertDatabaseMissing('question_issue_reports', ['question_id' => $questionId]);
        $this->assertDatabaseMissing('worksheet_question', ['question_id' => $questionId]);
    }

    public function test_admin_can_delete_question_and_return_to_set_code(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task, $question] = $this->seedPublishedFillBlankWithUploader();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        app(UserGroupService::class)->attachGroupByCode($admin, User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->delete(route('admin.questions.destroy', $question), ['return_to' => 'set-code'])
            ->assertRedirect(route('admin.questions.set-code', ['code' => 'SF751']));

        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2: ContentUploadTask, 3: Question}
     */
    private function seedPublishedFillBlankWithUploader(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $uploader = User::factory()->create([
            'role' => User::ROLE_CONTENT_UPLOADER,
            'name' => 'Uploader',
            'email' => 'uploader-misprint@example.com',
        ]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

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
        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Lines',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $syllabusChapter->id,
            'name' => 'Angles',
            'sort_order' => 1,
        ]);

        $textbook = Textbook::query()->create([
            'name' => 'Ganita',
            'code' => 'GP',
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'created_by' => $admin->id,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'FIB set',
            'set_number' => 1,
            'set_code' => 'SF751',
            'status' => Worksheet::STATUS_PUBLISHED,
            'syllabus_topic_id' => $topic->id,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => 'Angles ___°',
            'difficulty' => 'easy',
        ]);
        $worksheet->questions()->attach($question->id, ['sort_order' => 0]);
        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'answer_format' => 'integer',
            'correct_answer' => '90',
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Lines',
            'fill_blank_worksheet_id' => $worksheet->id,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'published_by' => $admin->id,
            'offered_amount_inr' => 1000,
            'agreed_amount_inr' => 1000,
            'agreed_at' => now()->subDays(2),
        ]);

        return [$uploader, $chapter, $task, $question];
    }

    /**
     * @return array{0: Student}
     */
    private function seedStudent(): array
    {
        $year = AcademicYear::query()->firstOrFail();
        $board = Board::query()->firstOrFail();
        $grade = GradeLevel::query()->firstOrFail();

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Divyush',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);
        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        return [$student];
    }

    private function seedInProgressBatchAttempt(Student $student, Question $question): \App\Models\SetAttempt
    {
        $enrollment = $student->enrollments()->firstOrFail();
        $worksheet = $question->worksheets()->firstOrFail();

        $assignment = \App\Models\SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'status' => \App\Models\SetAssignment::STATUS_IN_PROGRESS,
            'assigned_at' => now(),
        ]);

        return \App\Models\SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => \App\Models\SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => \App\Models\SetAttempt::STATUS_IN_PROGRESS,
        ]);
    }
}
