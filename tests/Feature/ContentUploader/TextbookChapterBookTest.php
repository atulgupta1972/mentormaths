<?php

namespace Tests\Feature\ContentUploader;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TextbookChapterBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploader_can_change_book_before_submit(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task, $otherBook] = $this->seedTask();

        $this->actingAs($uploader)
            ->post(route('content.textbooks.change-book', $chapter), [
                'textbook_id' => $otherBook->id,
            ])
            ->assertRedirect(route('content.textbooks.show', $chapter));

        $chapter->refresh();

        $this->assertSame($otherBook->id, $chapter->textbook_id);
    }

    public function test_uploader_cannot_change_book_after_submit(): void
    {
        [$uploader, $chapter, $task, $otherBook] = $this->seedTask();

        $task->update(['status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH, 'submitted_at' => now()]);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.change-book', $chapter), [
                'textbook_id' => $otherBook->id,
            ])
            ->assertSessionHas('error');
    }

    public function test_admin_can_change_book_after_publish(): void
    {
        [$uploader, $chapter, $task, $otherBook] = $this->seedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update(['status' => ContentUploadTask::STATUS_PUBLISHED, 'published_at' => now()]);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.change-book', $chapter), [
                'textbook_id' => $otherBook->id,
            ])
            ->assertRedirect(route('admin.textbooks.show', $chapter));

        $this->assertSame($otherBook->id, $chapter->fresh()->textbook_id);
    }

    public function test_mark_uploaded_requires_chapter_pdf(): void
    {
        [$uploader, $chapter, $task] = $this->seedTask(withPdf: false);

        $this->actingAs($uploader)
            ->post(route('content.tasks.mark-uploaded', $task))
            ->assertSessionHas('error');
    }

    public function test_uploader_can_upload_chapter_pdf(): void
    {
        Storage::fake('public');

        [$uploader, $chapter] = $this->seedTask(withPdf: false);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.upload-pdf', $chapter), [
                'pdf' => UploadedFile::fake()->create('chapter.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('content.textbooks.show', $chapter));

        $chapter->refresh();

        $this->assertNotNull($chapter->pdf_path);
        Storage::disk('public')->assertExists($chapter->pdf_path);
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2: ContentUploadTask, 3: Textbook}
     */
    private function seedTask(bool $withPdf = true): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Algebra',
            'chapter_number' => '4',
            'sort_order' => 4,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $bookA = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Wrong Book',
            'code' => 'wrong',
            'created_by' => $admin->id,
        ]);

        $bookB = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'RD Sharma',
            'code' => 'rdsharma',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $bookA->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 4,
            'title' => 'Algebraic Identities',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        if ($withPdf) {
            Storage::fake('public');
            $pdfPath = 'textbooks/'.$bookA->id.'/chapters/'.$chapter->chapter_number.'/test.pdf';
            Storage::disk('public')->put($pdfPath, '%PDF-1.4 test');
            $chapter->update(['pdf_path' => $pdfPath]);
        }

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 100,
            'agreed_amount_inr' => 100,
            'agreed_at' => now(),
        ]);

        return [$uploader, $chapter->fresh(), $task, $bookB];
    }
}
