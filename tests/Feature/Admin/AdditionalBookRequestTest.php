<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentTaskAdditionalBookUploader;
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
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdditionalBookRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_keep_the_upload_and_ask_for_another_book_at_the_same_chapter_rate(): void
    {
        $this->withoutVite();
        Mail::fake();

        [$admin, $uploader, $chapter, $task] = $this->seedSubmittedChapter();

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.request-additional-book', $task), [
                'decision' => 'partial',
                'note' => 'This book is short. Add another book for the same chapter.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $chapter->refresh();

        $this->assertSame(ContentUploadTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertSame(ContentUploadTask::DECISION_PARTIAL, $task->review_decision);
        $this->assertSame($chapter->id, $task->textbook_chapter_id);
        $this->assertSame(5000, $task->payableAmountInr());

        Mail::assertSent(ContentTaskAdditionalBookUploader::class);

        $this->actingAs($uploader)
            ->post(route('content.tasks.additional-book', $task), [
                'book_name' => 'RS Aggarwal',
                'book_code' => 'rsa',
            ])
            ->assertRedirect();

        $child = ContentUploadTask::query()->where('parent_task_id', $task->id)->first();
        $this->actingAs($uploader)
            ->get(route('content.textbooks.show', $child->textbook_chapter_id))
            ->assertOk();
        $this->assertNotNull($child);
        $this->assertNotSame($chapter->textbook_id, $child->textbookChapter->textbook_id);
        $this->assertSame(0, $child->payableAmountInr());
        $this->assertFalse($child->isPayable());
        $this->assertSame($chapter->id, $task->fresh()->textbook_chapter_id);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.publish-together', $task))
            ->assertRedirect()
            ->assertSessionHas('error');

        $child->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.publish-together', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentUploadTask::STATUS_PUBLISHED, $task->fresh()->status);
        $this->assertSame(ContentUploadTask::STATUS_PUBLISHED, $child->fresh()->status);
        $this->assertSame(5000, $task->fresh()->payableAmountInr());
        $this->assertSame(0, $child->fresh()->payableAmountInr());
        $this->assertFalse($child->fresh()->isPayable());
    }

    public function test_uploader_cannot_add_the_same_book_again(): void
    {
        $this->withoutVite();
        [$admin, $uploader, $chapter, $task] = $this->seedSubmittedChapter();

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.request-additional-book', $task), [
                'decision' => 'not_ok_book',
                'note' => 'Wrong book. Add a different book. This upload stays.',
            ])
            ->assertRedirect();

        $this->actingAs($uploader)
            ->from(route('content.tasks.show', $task))
            ->post(route('content.tasks.additional-book', $task), [
                'textbook_id' => $chapter->textbook_id,
            ])
            ->assertRedirect(route('content.tasks.show', $task))
            ->assertSessionHas('error');

        $this->assertSame(0, ContentUploadTask::query()->where('parent_task_id', $task->id)->count());
        $this->assertSame($chapter->textbook_id, $task->fresh()->textbookChapter->textbook_id);
    }

    /**
     * @return array{0: User, 1: User, 2: TextbookChapter, 3: ContentUploadTask}
     */
    private function seedSubmittedChapter(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Cubes & Cube Roots',
            'chapter_number' => '4',
            'sort_order' => 4,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'name' => 'Mentormathcs ICSE',
            'code' => 'mmicse',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 4,
            'title' => 'Cubes & Cube Roots',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
        ]);

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
            'submitted_at' => now(),
        ]);

        return [$admin, $uploader, $chapter, $task];
    }
}
