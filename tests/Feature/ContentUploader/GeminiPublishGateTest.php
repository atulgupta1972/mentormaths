<?php

namespace Tests\Feature\ContentUploader;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Services\ContentAiVerificationService;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiPublishGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_uploader_cannot_submit_for_publish_until_gemini_is_complete(): void
    {
        Mail::fake();
        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $task->update(['status' => ContentUploadTask::STATUS_VERIFIED]);

        $this->actingAs($uploader)
            ->from(route('content.tasks.show', $task))
            ->post(route('content.tasks.submit-for-publish', $task))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(ContentUploadTask::STATUS_VERIFIED, $task->fresh()->status);
    }

    public function test_uploader_can_submit_for_publish_after_gemini_approve(): void
    {
        Mail::fake();
        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $task->update(['status' => ContentUploadTask::STATUS_VERIFIED]);

        $this->actingAs($uploader)
            ->get(route('content.tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $uploader->id)
            ->orderByDesc('id')
            ->value('id');

        $this->assertNotNull($runId, 'Verification run should exist after viewing the task.');

        ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->update([
                'ai_verdict' => ContentAiVerificationService::VERDICT_APPROVE,
                'ai_confidence' => 'high',
                'ai_note' => null,
                'ai_reviewed_at' => now(),
            ]);

        $this->actingAs($uploader)
            ->from(route('content.tasks.show', $task))
            ->post(route('content.tasks.submit-for-publish', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH, $task->fresh()->status);
    }

    public function test_uploader_can_submit_when_remaining_rows_are_skipped(): void
    {
        Mail::fake();
        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $task->update(['status' => ContentUploadTask::STATUS_VERIFIED]);

        $this->actingAs($uploader)
            ->get(route('content.tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $uploader->id)
            ->orderByDesc('id')
            ->value('id');

        ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->update([
                'skipped' => true,
                'skip_reason' => 'Irrelevant',
                'skipped_at' => now(),
                'verified_at' => now(),
                // Older skips may lack ai_verdict=skip — gate must still pass.
                'ai_verdict' => null,
            ]);

        $this->actingAs($uploader)
            ->from(route('content.tasks.show', $task))
            ->post(route('content.tasks.submit-for-publish', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH, $task->fresh()->status);
    }

    public function test_uploader_can_submit_after_human_fix_of_gemini_flagged_rows(): void
    {
        Mail::fake();
        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $task->update(['status' => ContentUploadTask::STATUS_VERIFIED]);

        $this->actingAs($uploader)
            ->get(route('content.tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $uploader->id)
            ->orderByDesc('id')
            ->value('id');

        ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->update([
                'ai_verdict' => ContentAiVerificationService::VERDICT_NEEDS_FIX,
                'ai_note' => 'Wrong answer',
                'ai_reviewed_at' => now(),
                'verified_at' => now(),
                'check_text' => true,
                'check_options' => true,
                'check_correct' => true,
                'check_hint' => true,
                'check_explanation' => true,
                'check_difficulty' => true,
                'check_diagram' => true,
            ]);

        $this->actingAs($uploader)
            ->from(route('content.tasks.show', $task))
            ->post(route('content.tasks.submit-for-publish', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH, $task->fresh()->status);
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2: ContentUploadTask}
     */
    private function seedPublishedTask(): array
    {
        Storage::fake('public');

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

        $pdfPath = 'textbooks/'.$textbook->id.'/chapters/'.$chapter->chapter_number.'/test.pdf';
        Storage::disk('public')->put($pdfPath, '%PDF-1.4 test');
        $chapter->update(['pdf_path' => $pdfPath]);

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
                'topic' => 'Data',
                'question' => 'Who scored the highest marks?',
                'options' => ['Anya', 'Bhuvan', 'Cyra', 'Dev', 'Esha', 'Farid', 'Gita', 'Hari'],
                'correct_index' => 1,
                'hint' => 'Compare totals',
                'explanation' => 'Bhuvan had the highest score',
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

