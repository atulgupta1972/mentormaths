<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentTaskReturnedUploader;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\GradeLevel;
use App\Models\QuestionResolutionItem;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HelpRequestReturnToUploaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_only_the_help_request_question_back_to_uploader(): void
    {
        Mail::fake();
        $this->withoutVite();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        [$uploader, $chapter, $task, $firstQuestionId, $secondQuestionId] = $this->seedPublishedTaskWithTwoQuestions();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'published_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-batch', $task), [
                'run_id' => $runId,
                'question_ids' => [$firstQuestionId, $secondQuestionId],
            ])
            ->assertRedirect();

        $helpItem = $this->seedHelpRequest($chapter, $firstQuestionId);

        $payload = app(\App\Services\QuestionResolutionService::class)
            ->pendingForStudentIds([$helpItem->enrollment->student_id])
            ->first();

        $this->assertTrue($payload['can_return_to_uploader']);
        $this->assertSame($task->id, $payload['content_task_id']);
        $this->assertSame($uploader->name, $payload['uploader_name']);

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('admin.help-requests.return-to-uploader', $helpItem), [
                'issue' => 'wrong_answer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS, $task->status);
        $this->assertStringContainsString('Wrong answer', (string) $task->admin_notes);
        $this->assertStringContainsString((string) $firstQuestionId, (string) $task->admin_notes);

        $firstCheck = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->where('question_id', $firstQuestionId)
            ->first();
        $secondCheck = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->where('question_id', $secondQuestionId)
            ->first();

        $this->assertFalse($firstCheck->isComplete());
        $this->assertTrue($secondCheck->isComplete());

        Mail::assertNothingSent();

        $correction = \App\Models\ContentQuestionCorrection::query()
            ->where('content_upload_task_id', $task->id)
            ->where('question_id', $firstQuestionId)
            ->where('status', \App\Models\ContentQuestionCorrection::STATUS_PENDING)
            ->first();

        $this->assertNotNull($correction);

        $dashboard = app(\App\Services\ContentUploaderDashboardService::class)->forUser($uploader);
        $this->assertSame(1, $dashboard['summary']['corrections_pending']);
        $this->assertSame($correction->id, $dashboard['correctionsPending'][0]['id']);

        $this->actingAs($uploader)
            ->post(route('content.corrections.start', $correction))
            ->assertRedirect(route('content.tasks.show', $task));

        Mail::assertSent(ContentTaskReturnedUploader::class, function (ContentTaskReturnedUploader $mail) use ($uploader, $firstQuestionId, $secondQuestionId) {
            return $mail->hasTo($uploader->email)
                && count($mail->returnItems) === 1
                && (int) $mail->returnItems[0]['question_id'] === $firstQuestionId
                && str_contains($mail->returnItems[0]['remark'], 'Wrong answer')
                && (int) $mail->returnItems[0]['question_id'] !== $secondQuestionId;
        });
    }

    public function test_admin_cannot_return_a_sum_with_no_uploader(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $item = $this->seedHelpRequestWithoutUploader();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->from(route('admin.students.show', $item->enrollment->student_id))
            ->post(route('admin.help-requests.return-to-uploader', $item), [
                'issue' => 'incomplete',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2: ContentUploadTask, 3: int, 4: int}
     */
    private function seedPublishedTaskWithTwoQuestions(): array
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

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Patterns',
            'chapter_number' => 'Ch 1',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Patterns',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
        ]);

        $json = json_encode([
            'questions' => [
                [
                    'topic' => 'Addition',
                    'question' => 'What is 2 + 2?',
                    'options' => ['3', '4', '5', '6'],
                    'correct_index' => 0,
                    'hint' => 'Add',
                    'explanation' => 'Wrong on purpose',
                    'difficulty' => 'Easy',
                ],
                [
                    'topic' => 'Addition',
                    'question' => 'What is 3 + 3?',
                    'options' => ['5', '6', '7', '8'],
                    'correct_index' => 1,
                    'hint' => 'Add',
                    'explanation' => 'Fine',
                    'difficulty' => 'Easy',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.import-mcq', $chapter), ['json' => $json]);

        $chapter->refresh();

        $this->actingAs($uploader)
            ->post(route('content.textbooks.publish', $chapter), [
                'items' => $chapter->extraction_items,
            ]);

        $chapter = $chapter->fresh();
        $questionIds = Worksheet::query()
            ->findOrFail($chapter->mcqWorksheetIds()[0])
            ->questions()
            ->orderByPivot('sort_order')
            ->pluck('questions.id')
            ->all();

        $this->assertCount(2, $questionIds);

        return [$uploader, $chapter, $task->fresh(), (int) $questionIds[0], (int) $questionIds[1]];
    }

    private function seedHelpRequest(TextbookChapter $chapter, int $questionId): QuestionResolutionItem
    {
        $year = AcademicYear::query()->firstOrFail();
        $board = Board::query()->firstOrFail();
        $grade = GradeLevel::query()->firstOrFail();

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Help Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        return QuestionResolutionItem::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'question_id' => $questionId,
            'status' => QuestionResolutionItem::STATUS_PENDING,
            'gave_up_at' => now(),
        ]);
    }

    private function seedHelpRequestWithoutUploader(): QuestionResolutionItem
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
        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
        $topic = \App\Models\SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $syllabusChapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);
        $question = \App\Models\Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is 1 + 1?',
            'type' => \App\Models\Question::TYPE_MCQ,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Solo Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        return QuestionResolutionItem::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'question_id' => $question->id,
            'status' => QuestionResolutionItem::STATUS_PENDING,
            'gave_up_at' => now(),
        ]);
    }
}
