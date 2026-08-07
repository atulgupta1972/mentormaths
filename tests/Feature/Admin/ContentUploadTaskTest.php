<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentTaskAssignedUploader;
use App\Models\ContentRateCard;
use App\Models\ContentUploadTask;
use App\Models\AcademicYear;
use App\Models\Board;
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

class ContentUploadTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_assign_chapter_and_uploader_agrees(): void
    {
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Test Book',
            'code' => 'TB',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Sample chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        ContentRateCard::create([
            'grade_level_id' => $grade->id,
            'content_type' => ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ,
            'default_amount_inr' => 6000,
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.content-tasks.store'), [
                'assigned_to_user_id' => $uploader->id,
                'book_name' => 'Ganita Prakash',
                'book_code' => 'GP',
                'syllabus_chapter_ids' => [$syllabusChapter->id],
            ])
            ->assertRedirect(route('admin.content-tasks.index'));

        $task = ContentUploadTask::query()->firstOrFail();
        $this->assertSame(ContentUploadTask::STATUS_PENDING_AGREEMENT, $task->status);
        $this->assertSame(6000, $task->offered_amount_inr);

        Mail::assertSent(ContentTaskAssignedUploader::class, function ($mail) use ($uploader) {
            if (! $mail->hasTo($uploader->email)) {
                return false;
            }

            $html = $mail->render();

            return str_contains($html, 'content upload (MCQ)')
                && str_contains($html, 'Ganita Prakash')
                && str_contains($html, 'Brief process')
                && str_contains($html, 'verify each question');
        });

        $this->actingAs($uploader)
            ->post(route('content.tasks.agree', $task))
            ->assertRedirect();

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertSame(6000, $task->agreed_amount_inr);
        $this->assertNotNull($task->agreed_at);
    }

    public function test_duplicate_assignment_blocked_without_override(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Test Book',
            'code' => 'TB2',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Another chapter',
            'pdf_path' => 'textbooks/sample2.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        ContentRateCard::create([
            'grade_level_id' => $grade->id,
            'content_type' => ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ,
            'default_amount_inr' => 5000,
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
        ]);

        $other = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($other, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->from(route('admin.content-tasks.create'))
            ->post(route('admin.content-tasks.store'), [
                'assigned_to_user_id' => $other->id,
                'textbook_id' => $textbook->id,
                'syllabus_chapter_ids' => [$syllabusChapter->id],
            ])
            ->assertRedirect(route('admin.content-tasks.create'))
            ->assertSessionHas('error');

        $this->assertSame(1, ContentUploadTask::query()->count());
    }

    /**
     * @return array{0: GradeLevel, 1: SyllabusChapter, 2: User}
     */
    private function seedGradeAndAdmin(): array
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

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Sequences',
            'chapter_number' => 'Ch 8',
            'sort_order' => 8,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $chapter, $admin];
    }
}
