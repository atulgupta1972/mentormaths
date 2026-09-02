<?php

namespace Tests\Feature\ContentUploader;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentQuestionDeleteRequest;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\TextbookChapterMcqImportService;
use App\Services\UserGroupService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentChapterLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_uploader_can_browse_class_then_chapter_questions(): void
    {
        [$uploader, $chapter] = $this->seedChapterWithQuestions();

        $this->actingAs($uploader)
            ->get(route('content.chapters.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Chapters/Index')
                ->has('grades', 1)
                ->has('chapters', 1)
                ->where('chapters.0.title', 'Patterns')
                ->where('chapters.0.question_count', 1));

        $this->actingAs($uploader)
            ->get(route('content.chapters.show', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Chapters/Show')
                ->where('task.can_delete', true)
                ->has('questions', 1)
                ->where('questions.0.question_text', 'What is 2 + 2?'));
    }

    public function test_uploader_can_append_questions_without_replacing(): void
    {
        [$uploader, $chapter] = $this->seedChapterWithQuestions();

        $this->actingAs($uploader)
            ->post(route('content.chapters.append-mcq', $chapter), [
                'json' => $this->questionJson('What is 3 + 3?', ['5', '6', '7', '8'], 1),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertCount(2, $chapter->extraction_items ?? []);
        $this->assertSame('What is 2 + 2?', $chapter->extraction_items[0]['question_text']);
        $this->assertSame('What is 3 + 3?', $chapter->extraction_items[1]['question_text']);
    }

    public function test_uploader_can_delete_before_submit_and_must_request_after(): void
    {
        [$uploader, $chapter, $task] = $this->seedChapterWithQuestions(withTask: true);

        $this->actingAs($uploader)
            ->post(route('content.chapters.append-mcq', $chapter), [
                'json' => $this->questionJson('What is 9 + 1?', ['9', '10', '11', '12'], 1),
            ])
            ->assertRedirect();

        $chapter->refresh();
        $this->assertCount(2, $chapter->extraction_items ?? []);

        $this->actingAs($uploader)
            ->post(route('content.chapters.delete-question', $chapter), ['item_index' => 1])
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertCount(1, $chapter->extraction_items ?? []);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.publish', $chapter), [
                'items' => $chapter->extraction_items,
            ])
            ->assertRedirect();

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->actingAs($uploader)
            ->from(route('content.chapters.show', $chapter))
            ->post(route('content.chapters.delete-question', $chapter), ['item_index' => 0])
            ->assertRedirect(route('content.chapters.show', $chapter))
            ->assertSessionHas('error');

        $this->actingAs($uploader)
            ->post(route('content.chapters.request-delete', $chapter), [
                'item_index' => 0,
                'reason' => 'Duplicate of another uploaded question.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('content_question_delete_requests', [
            'textbook_chapter_id' => $chapter->id,
            'item_index' => 0,
            'status' => ContentQuestionDeleteRequest::STATUS_PENDING,
        ]);
    }

    public function test_append_after_publish_adds_to_existing_worksheet(): void
    {
        [$uploader, $chapter, $task] = $this->seedChapterWithQuestions(withTask: true);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.publish', $chapter), [
                'items' => $chapter->extraction_items,
            ])
            ->assertRedirect();

        $chapter->refresh();
        $worksheetId = $chapter->mcqWorksheetIds()[0];
        $this->assertSame(1, Worksheet::query()->findOrFail($worksheetId)->questions()->count());

        $task->update([
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->actingAs($uploader)
            ->post(route('content.chapters.append-mcq', $chapter), [
                'json' => $this->questionJson('What is 8 + 2?', ['8', '9', '10', '11'], 2),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertCount(2, $chapter->extraction_items ?? []);
        $this->assertSame(2, Worksheet::query()->findOrFail($worksheetId)->questions()->count());
        $this->assertSame($worksheetId, $chapter->mcqWorksheetIds()[0]);
    }

    public function test_admin_can_approve_delete_request_after_publish(): void
    {
        [$uploader, $chapter, $task] = $this->seedChapterWithQuestions(withTask: true);
        $admin = User::query()->where('role', User::ROLE_ADMIN)->firstOrFail();

        $this->actingAs($uploader)
            ->post(route('content.textbooks.publish', $chapter), [
                'items' => $chapter->extraction_items,
            ])
            ->assertRedirect();

        $task->update([
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->actingAs($uploader)
            ->post(route('content.chapters.request-delete', $chapter), [
                'item_index' => 0,
                'reason' => 'Wrong question for this chapter.',
            ])
            ->assertRedirect();

        $deleteRequest = ContentQuestionDeleteRequest::query()->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('deleteRequests', 1)
                ->where('deleteRequests.0.status', 'pending'));

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.delete-requests.approve', [$task, $deleteRequest]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertCount(0, $chapter->extraction_items ?? []);
        $this->assertSame(ContentQuestionDeleteRequest::STATUS_APPROVED, $deleteRequest->fresh()->status);
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2?: ContentUploadTask}
     */
    private function seedChapterWithQuestions(bool $withTask = false): array
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

        Storage::fake('public');
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

        app(TextbookChapterMcqImportService::class)->import(
            $chapter,
            $this->questionJson('What is 2 + 2?', ['3', '4', '5', '6'], 1),
        );

        $chapter->refresh();

        if ($withTask) {
            return [$uploader, $chapter, $task];
        }

        return [$uploader, $chapter];
    }

    private function questionJson(string $question, array $options, int $correctIndex): string
    {
        return json_encode([
            'questions' => [[
                'topic' => 'Addition',
                'question' => $question,
                'options' => $options,
                'correct_index' => $correctIndex,
                'hint' => 'Add',
                'explanation' => 'Add the numbers.',
                'difficulty' => 'Easy',
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
