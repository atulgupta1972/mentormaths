<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentTaskReturnedUploader;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationRun;
use App\Models\GradeLevel;
use App\Models\QuestionOption;
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

class ContentTaskVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_mcq_options_during_verification(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($uploader)
            ->post(route('content.tasks.mark-uploaded', $task))
            ->assertRedirect();

        $task->update(['status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH, 'submitted_at' => now()]);

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;
        $options = QuestionOption::query()->where('question_id', $questionId)->orderBy('sort_order')->get();

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('verification.questions', 1));

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-question', $task), [
                'run_id' => $runId,
                'question_id' => $questionId,
                'question_text' => 'What is two plus two?',
                'explanation' => 'Answer is C.',
                'method_hint' => 'Add',
                'difficulty' => 'Easy',
                'options' => $options->map(fn (QuestionOption $option, int $index) => [
                    'id' => $option->id,
                    'option_text' => $option->option_text,
                    'is_correct' => $index === 2,
                ])->values()->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(
            QuestionOption::query()
                ->where('question_id', $questionId)
                ->where('is_correct', true)
                ->where('sort_order', 3)
                ->exists()
        );
    }

    public function test_admin_can_send_task_back_for_reverification(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$uploader, , $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.return-for-reverification', $task), [
                'reason' => 'All answers are option A — please fix.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS, $task->status);
        $this->assertNull($task->submitted_at);
        $this->assertStringContainsString('All answers are option A', (string) $task->admin_notes);

        Mail::assertSent(ContentTaskReturnedUploader::class, fn ($mail) => $mail->hasTo($uploader->email));
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2: ContentUploadTask}
     */
    private function seedPublishedTask(): array
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
            'questions' => [[
                'topic' => 'Addition',
                'question' => 'What is 2 + 2?',
                'options' => ['3', '4', '5', '6'],
                'correct_index' => 0,
                'hint' => 'Add',
                'explanation' => 'Wrong on purpose',
                'difficulty' => 'Easy',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.import-mcq', $chapter), ['json' => $json]);

        $chapter->refresh();

        $this->actingAs($uploader)
            ->post(route('content.textbooks.publish', $chapter), [
                'items' => $chapter->extraction_items,
            ]);

        return [$uploader, $chapter->fresh(), $task->fresh()];
    }
}
