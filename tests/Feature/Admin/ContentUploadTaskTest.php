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
                'rate_basis' => ContentRateCard::BASIS_PER_SET,
            ])
            ->assertRedirect(route('admin.content-tasks.index'));

        $task = ContentUploadTask::query()->firstOrFail();
        $this->assertSame(ContentUploadTask::STATUS_PENDING_AGREEMENT, $task->status);
        $this->assertSame(6000, $task->offered_amount_inr);
        $this->assertSame(ContentRateCard::BASIS_PER_SET, $task->rate_basis);

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

    public function test_admin_content_tasks_index_shows_allocation_matrix_and_drill_down(): void
    {
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        ContentRateCard::create([
            'grade_level_id' => $grade->id,
            'content_type' => ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ,
            'default_amount_inr' => 5000,
        ]);

        $uploader = User::factory()->create([
            'name' => 'Matrix Mentor',
            'role' => User::ROLE_TEACHER,
        ]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.content-tasks.store'), [
                'assigned_to_user_id' => $uploader->id,
                'book_name' => 'Ganita Prakash',
                'book_code' => 'GP',
                'syllabus_chapter_ids' => [$syllabusChapter->id],
                'rate_basis' => ContentRateCard::BASIS_PER_SET,
                'offered_amount_inr' => 5000,
            ])
            ->assertRedirect(route('admin.content-tasks.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('email_sent', true);

        $boardId = Board::query()->where('code', 'CBSE')->value('id');

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.index', [
                'board_id' => $boardId,
                'drill_grade_id' => $grade->id,
                'drill_uploader_id' => $uploader->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ContentTasks/Index')
                ->has('matrix.uploaders')
                ->has('matrix.grades')
                ->where('matrix.total_assignments', 1)
                ->where('matrix.database_total', 1)
                ->where('matrix.drill.uploader.name', 'Matrix Mentor')
                ->has('matrix.drill.chapters', 1)
                ->where('matrix.drill.chapters.0.status_group', 'awaiting')
                ->where('matrix.drill.breakup.under_review', 1)
                ->where('matrix.drill.breakup.submitted', 0)
                ->where('matrix.drill.breakup.published', 0));
    }

    public function test_allocation_matrix_drill_down_breaks_up_review_submitted_and_published(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP-DRILL',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $uploader = User::factory()->create([
            'name' => 'Breakup Mentor',
            'role' => User::ROLE_TEACHER,
        ]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $reviewSyllabus = $syllabusChapter;
        $submittedSyllabus = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabusChapter->syllabus_version_id,
            'name' => 'Submitted topic',
            'chapter_number' => 'Ch 2',
            'sort_order' => 2,
        ]);
        $publishedSyllabus = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabusChapter->syllabus_version_id,
            'name' => 'Published topic',
            'chapter_number' => 'Ch 3',
            'sort_order' => 3,
        ]);

        $reviewChapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $reviewSyllabus->id,
            'chapter_number' => 1,
            'title' => 'Review chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $submittedChapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $submittedSyllabus->id,
            'chapter_number' => 2,
            'title' => 'Submitted chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $publishedChapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $publishedSyllabus->id,
            'chapter_number' => 3,
            'title' => 'Published chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        ContentUploadTask::create([
            'textbook_chapter_id' => $reviewChapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
        ]);
        $submitted = ContentUploadTask::create([
            'textbook_chapter_id' => $submittedChapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
            'submitted_at' => now(),
        ]);
        ContentUploadTask::create([
            'textbook_chapter_id' => $publishedChapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
            'submitted_at' => now(),
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.index', [
                'drill_grade_id' => $grade->id,
                'drill_uploader_id' => $uploader->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ContentTasks/Index')
                ->where('matrix.drill.breakup.under_review', 1)
                ->where('matrix.drill.breakup.submitted', 1)
                ->where('matrix.drill.breakup.published', 1)
                ->has('matrix.drill.chapters', 3)
                ->where('matrix.drill.chapters', function ($chapters) use ($submitted) {
                    return collect($chapters)->contains(fn ($row) => (int) $row['id'] === $submitted->id
                        && $row['breakup_bucket'] === 'submitted'
                        && $row['can_review_and_publish'] === true);
                }));
    }

    public function test_allocation_matrix_shows_assignments_without_board_filter(): void
    {
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        ContentRateCard::create([
            'grade_level_id' => $grade->id,
            'content_type' => ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ,
            'default_amount_inr' => 5000,
        ]);

        $uploader = User::factory()->create([
            'name' => 'Visible Mentor',
            'role' => User::ROLE_TEACHER,
        ]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.content-tasks.store'), [
                'assigned_to_user_id' => $uploader->id,
                'book_name' => 'Ganita Prakash',
                'book_code' => 'GP',
                'syllabus_chapter_ids' => [$syllabusChapter->id],
                'rate_basis' => ContentRateCard::BASIS_PER_SET,
                'offered_amount_inr' => 5000,
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ContentTasks/Index')
                ->where('matrix.board_id', null)
                ->where('matrix.total_assignments', 1)
                ->where("matrix.cells.{$grade->id}.{$uploader->id}.count", 1));
    }

    public function test_store_uses_default_per_question_rate_when_matrix_empty(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.content-tasks.store'), [
                'assigned_to_user_id' => $uploader->id,
                'book_name' => 'Ganita Prakash',
                'book_code' => 'GP',
                'syllabus_chapter_ids' => [$syllabusChapter->id],
                'rate_basis' => ContentRateCard::BASIS_PER_QUESTION,
            ])
            ->assertRedirect(route('admin.content-tasks.index'));

        $task = ContentUploadTask::query()->firstOrFail();
        $this->assertSame(ContentRateCard::BASIS_PER_QUESTION, $task->rate_basis);
        $this->assertSame(2, $task->offered_amount_inr);
    }

    public function test_admin_can_assign_per_question_rate_override(): void
    {
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.content-tasks.store'), [
                'assigned_to_user_id' => $uploader->id,
                'book_name' => 'Ganita Prakash',
                'book_code' => 'GP',
                'syllabus_chapter_ids' => [$syllabusChapter->id],
                'rate_basis' => ContentRateCard::BASIS_PER_QUESTION,
                'offered_amount_inr' => 2,
            ])
            ->assertRedirect(route('admin.content-tasks.index'));

        $task = ContentUploadTask::query()->firstOrFail();
        $this->assertSame(ContentRateCard::BASIS_PER_QUESTION, $task->rate_basis);
        $this->assertSame(2, $task->offered_amount_inr);
        $this->assertStringContainsString('per verified question', $task->rateDescription());
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
                'rate_basis' => ContentRateCard::BASIS_PER_SET,
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
