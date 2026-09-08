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
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiUploadGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_uploaded_chapter_dashboard_shows_gemini_pending(): void
    {
        Mail::fake();
        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $this->actingAs($uploader)
            ->get(route('content.chapters.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Chapters/Index')
                ->where('gemini_blocked', true)
                ->where('gemini_pending_count', 1)
                ->where('chapters.0.needs_gemini_check', true)
                ->where('chapters.0.task_id', $task->id)
                ->where('gemini_pending.0.id', $task->id));

        $this->actingAs($uploader)
            ->get(route('content.tasks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Tasks/Index')
                ->where('summary.gemini_pending', 1)
                ->where('geminiPending.0.id', $task->id)
                ->where('geminiPending.0.needs_gemini_check', true));
    }

    public function test_uploader_can_view_uploaded_chapter_but_cannot_add_questions_while_gemini_pending(): void
    {
        Mail::fake();
        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $this->actingAs($uploader)
            ->get(route('content.chapters.show', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Chapters/Show')
                ->where('gemini_blocked', true)
                ->where('task.can_add', false)
                ->where('task.needs_gemini_check', true)
                ->where('task.id', $task->id));

        $this->actingAs($uploader)
            ->from(route('content.chapters.show', $chapter))
            ->post(route('content.chapters.append-mcq', $chapter), [
                'json' => $this->questionJson('What is 9 + 1?', ['9', '10', '11', '12'], 1),
            ])
            ->assertRedirect(route('content.chapters.show', $chapter))
            ->assertSessionHas('error');

        $this->assertStringContainsString(
            'Gemini check is pending',
            session('error'),
        );
    }

    public function test_uploader_cannot_open_chapter_editor_or_import_while_gemini_pending(): void
    {
        Mail::fake();
        [$uploader, $chapter] = $this->seedPublishedTask();

        $this->actingAs($uploader)
            ->get(route('content.textbooks.show', $chapter))
            ->assertRedirect(route('content.tasks.index'))
            ->assertSessionHas('error');

        $this->actingAs($uploader)
            ->from(route('content.tasks.index'))
            ->post(route('content.textbooks.import-mcq', $chapter), [
                'json' => $this->questionJson('What is 5 + 5?', ['8', '9', '10', '11'], 2),
            ])
            ->assertRedirect(route('content.tasks.index'))
            ->assertSessionHas('error');
    }

    public function test_uploader_can_append_after_gemini_is_complete(): void
    {
        Mail::fake();
        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $this->completeGeminiForTask($uploader, $task);

        $this->actingAs($uploader)
            ->get(route('content.chapters.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('gemini_blocked', false)
                ->where('gemini_pending_count', 0)
                ->where('chapters.0.needs_gemini_check', false));

        $this->actingAs($uploader)
            ->from(route('content.chapters.show', $chapter))
            ->post(route('content.chapters.append-mcq', $chapter), [
                'json' => $this->questionJson('What is 8 + 2?', ['8', '9', '10', '11'], 2),
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionMissing('error');
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

        $json = $this->questionJson('Who scored the highest marks?', ['Anya', 'Bhuvan', 'Cyra', 'Dev'], 1);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.import-mcq', $chapter), ['json' => $json]);

        $chapter->refresh();

        $this->actingAs($uploader)
            ->post(route('content.textbooks.publish', $chapter), [
                'items' => $chapter->extraction_items,
            ]);

        return [$uploader, $chapter->fresh(), $task->fresh()];
    }

    private function completeGeminiForTask(User $uploader, ContentUploadTask $task): void
    {
        $this->actingAs($uploader)
            ->get(route('content.tasks.show', $task))
            ->assertOk();

        $runIds = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $uploader->id)
            ->pluck('id')
            ->all();

        ContentVerificationCheck::query()
            ->whereIn('content_verification_run_id', $runIds)
            ->update([
                'ai_verdict' => ContentAiVerificationService::VERDICT_APPROVE,
                'ai_confidence' => 'high',
                'ai_note' => null,
                'ai_reviewed_at' => now(),
            ]);
    }

    private function questionJson(string $question, array $options, int $correctIndex): string
    {
        return json_encode([
            'questions' => [[
                'topic' => 'Data',
                'question' => $question,
                'options' => $options,
                'correct_index' => $correctIndex,
                'hint' => 'Compare totals',
                'explanation' => 'Bhuvan had the highest score',
                'difficulty' => 'Easy',
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
