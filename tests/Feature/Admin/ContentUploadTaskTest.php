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

    public function test_allocation_drill_uses_current_syllabus_chapter_name(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $syllabusChapter->update([
            'name' => 'Large Numbers Around Us',
            'chapter_number' => 'Ch 1',
        ]);

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP1',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $textbookChapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 16,
            'title' => 'Old stored title',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        ContentUploadTask::query()->create([
            'textbook_chapter_id' => $textbookChapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 100,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.index', [
                'drill_grade_id' => $grade->id,
                'drill_uploader_id' => $uploader->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('matrix.drill.chapters.0.chapter.chapter_number', 'Ch 1')
                ->where('matrix.drill.chapters.0.chapter.title', 'Large Numbers Around Us')
                ->where('matrix.drill.chapters.0.chapter.textbook_name', 'Ganita Prakash Part I'));
    }

    public function test_allocation_drill_merges_duplicate_same_book_and_syllabus_chapter(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $syllabusChapter->update([
            'name' => 'Working with Fractions',
            'chapter_number' => 'Ch 8',
        ]);

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP1',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $first = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => 'Working with Fractions',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $duplicate = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => 'Working with Fractions',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        ContentUploadTask::query()->create([
            'textbook_chapter_id' => $first->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
            'offered_amount_inr' => 2,
        ]);
        ContentUploadTask::query()->create([
            'textbook_chapter_id' => $duplicate->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
            'offered_amount_inr' => 2,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.index', [
                'drill_grade_id' => $grade->id,
                'drill_uploader_id' => $uploader->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('matrix.drill.chapters', 1)
                ->where('matrix.drill.chapters.0.chapter.chapter_number', 'Ch 8')
                ->where('matrix.drill.chapters.0.chapter.title', 'Working with Fractions'));

        $this->assertSame(
            1,
            TextbookChapter::query()
                ->where('textbook_id', $textbook->id)
                ->where('syllabus_chapter_id', $syllabusChapter->id)
                ->count(),
        );
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
                ->where("matrix.cells.{$grade->id}.{$uploader->id}.breakup.under_review", 1)
                ->where("matrix.cells.{$grade->id}.{$uploader->id}.breakup.submitted", 1)
                ->where("matrix.cells.{$grade->id}.{$uploader->id}.breakup.published", 1)
                ->has('matrix.drill.chapters', 3)
                ->where('matrix.drill.chapters', function ($chapters) use ($submitted) {
                    return collect($chapters)->contains(fn ($row) => (int) $row['id'] === $submitted->id
                        && $row['breakup_bucket'] === 'submitted'
                        && $row['can_review_and_publish'] === true);
                }));
    }

    public function test_allocation_matrix_shows_assignments_without_board_filter(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP-VISIBLE',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Assigned chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $uploader = User::factory()->create([
            'name' => 'Visible Mentor',
            'role' => User::ROLE_TEACHER,
        ]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $idle = User::factory()->create([
            'name' => 'Idle Mentor',
            'role' => User::ROLE_TEACHER,
        ]);
        app(UserGroupService::class)->attachGroupByCode($idle, User::ROLE_CONTENT_UPLOADER);
        GradeLevel::query()->create(['name' => 'Class 11', 'sort_order' => 11, 'is_active' => true]);

        ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
            'offered_amount_inr' => 5000,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ContentTasks/Index')
                ->where('matrix.board_id', null)
                ->where('matrix.total_assignments', 1)
                ->where("matrix.cells.{$grade->id}.{$uploader->id}.count", 1)
                ->where("matrix.cells.{$grade->id}.{$uploader->id}.breakup.under_review", 1)
                ->where('matrix.uploaders', fn ($uploaders) => collect($uploaders)->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) $uploader->id])
                ->where('matrix.grades', fn ($grades) => collect($grades)->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) $grade->id]));
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

    public function test_admin_can_reassign_unfinished_chapter_to_another_uploader(): void
    {
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        ]);
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Test Book',
            'code' => 'TB-RE',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Reassign me',
            'pdf_path' => 'textbooks/sample.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $first = User::factory()->create(['name' => 'First Uploader', 'role' => User::ROLE_TEACHER]);
        $second = User::factory()->create(['name' => 'Second Uploader', 'role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($first, User::ROLE_CONTENT_UPLOADER);
        app(UserGroupService::class)->attachGroupByCode($second, User::ROLE_CONTENT_UPLOADER);

        $task = ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $first->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->from(route('admin.content-tasks.show', $task))
            ->post(route('admin.content-tasks.reassign', $task), [
                'assigned_to_user_id' => $second->id,
                'note' => 'Could not finish',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame($second->id, $task->assigned_to_user_id);
        $this->assertSame(ContentUploadTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertStringContainsString('First Uploader → Second Uploader', (string) $task->admin_notes);
        $this->assertStringContainsString('Could not finish', (string) $task->admin_notes);
        $this->assertSame(1, ContentUploadTask::query()->count());

        Mail::assertSent(ContentTaskAssignedUploader::class, fn ($mail) => $mail->hasTo($second->email));
    }

    public function test_admin_cannot_reassign_published_chapter(): void
    {
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        ]);

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Test Book',
            'code' => 'TB-PUB',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Published chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $first = User::factory()->create(['role' => User::ROLE_TEACHER]);
        $second = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($first, User::ROLE_CONTENT_UPLOADER);
        app(UserGroupService::class)->attachGroupByCode($second, User::ROLE_CONTENT_UPLOADER);

        $task = ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $first->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.content-tasks.show', $task))
            ->post(route('admin.content-tasks.reassign', $task), [
                'assigned_to_user_id' => $second->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($first->id, $task->fresh()->assigned_to_user_id);
    }

    public function test_admin_can_bulk_reassign_unfinished_chapters(): void
    {
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        ]);
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Test Book',
            'code' => 'TB-BULK',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapters = collect([1, 2])->map(function (int $number) use ($textbook, $admin, $syllabusChapter) {
            $syllabusRow = $number === 1
                ? $syllabusChapter
                : SyllabusChapter::query()->create([
                    'syllabus_version_id' => $syllabusChapter->syllabus_version_id,
                    'name' => "Chapter {$number}",
                    'chapter_number' => "Ch {$number}",
                    'sort_order' => $number,
                ]);

            return TextbookChapter::create([
                'textbook_id' => $textbook->id,
                'syllabus_chapter_id' => $syllabusRow->id,
                'chapter_number' => $number,
                'title' => "Chapter {$number}",
                'pdf_path' => null,
                'status' => TextbookChapter::STATUS_DRAFT,
                'created_by' => $admin->id,
            ]);
        });

        $first = User::factory()->create(['name' => 'Smitha Rao', 'role' => User::ROLE_TEACHER]);
        $second = User::factory()->create(['name' => 'New Uploader', 'role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($first, User::ROLE_CONTENT_UPLOADER);
        app(UserGroupService::class)->attachGroupByCode($second, User::ROLE_CONTENT_UPLOADER);

        $tasks = $chapters->map(fn (TextbookChapter $chapter) => ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $first->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
            'offered_amount_inr' => 200,
        ]));

        $this->actingAs($admin)
            ->from(route('admin.content-tasks.index'))
            ->post(route('admin.content-tasks.bulk-reassign'), [
                'task_ids' => $tasks->pluck('id')->all(),
                'assigned_to_user_id' => $second->id,
                'note' => 'Move the Class 8 pile',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        foreach ($tasks as $task) {
            $task->refresh();
            $this->assertSame($second->id, $task->assigned_to_user_id);
            $this->assertStringContainsString('Smitha Rao → New Uploader', (string) $task->admin_notes);
            $this->assertStringContainsString('Move the Class 8 pile', (string) $task->admin_notes);
        }

        Mail::assertSent(ContentTaskAssignedUploader::class, 1);
        Mail::assertSent(ContentTaskAssignedUploader::class, fn ($mail) => $mail->hasTo($second->email));
    }

    public function test_admin_bulk_reassign_skips_published_chapters(): void
    {
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        ]);
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Test Book',
            'code' => 'TB-SKIP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $openChapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Open chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        $publishedSyllabus = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabusChapter->syllabus_version_id,
            'name' => 'Published chapter',
            'chapter_number' => 'Ch 2',
            'sort_order' => 2,
        ]);
        $publishedChapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $publishedSyllabus->id,
            'chapter_number' => 2,
            'title' => 'Published chapter',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $first = User::factory()->create(['role' => User::ROLE_TEACHER]);
        $second = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($first, User::ROLE_CONTENT_UPLOADER);
        app(UserGroupService::class)->attachGroupByCode($second, User::ROLE_CONTENT_UPLOADER);

        $openTask = ContentUploadTask::create([
            'textbook_chapter_id' => $openChapter->id,
            'assigned_to_user_id' => $first->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
            'offered_amount_inr' => 200,
        ]);
        $publishedTask = ContentUploadTask::create([
            'textbook_chapter_id' => $publishedChapter->id,
            'assigned_to_user_id' => $first->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 200,
            'agreed_amount_inr' => 200,
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.content-tasks.index'))
            ->post(route('admin.content-tasks.bulk-reassign'), [
                'task_ids' => [$openTask->id, $publishedTask->id],
                'assigned_to_user_id' => $second->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($second->id, $openTask->fresh()->assigned_to_user_id);
        $this->assertSame($first->id, $publishedTask->fresh()->assigned_to_user_id);
    }

    public function test_assign_form_lists_chapters_for_the_selected_board_only(): void
    {
        $this->withoutVite();

        [$grade, $cbseChapter, $admin] = $this->seedGradeAndAdmin();
        $cbseId = $cbseChapter->syllabusVersion->board_id;
        $yearId = $cbseChapter->syllabusVersion->academic_year_id;
        $subjectId = $cbseChapter->syllabusVersion->subject_id;

        $icse = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);
        $icseSyllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $yearId,
            'grade_level_id' => $grade->id,
            'board_id' => $icse->id,
            'subject_id' => $subjectId,
        ]);
        $icseChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $icseSyllabus->id,
            'name' => 'Rational and Irrational Numbers',
            'chapter_number' => 'Ch 1',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.content-tasks.create', ['board_id' => $cbseId]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ContentTasks/Create')
                ->where('selectedBoardId', $cbseId)
                ->has('boards', 2)
                ->where('syllabusChapters', fn ($chapters) => collect($chapters)->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) $cbseChapter->id]));

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.content-tasks.create', ['board_id' => $icse->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ContentTasks/Create')
                ->where('selectedBoard.code', 'ICSE')
                ->where('syllabusChapters', fn ($chapters) => collect($chapters)->pluck('id')->map(fn ($id) => (int) $id)->all() === [(int) $icseChapter->id]));
    }

    public function test_assign_form_collapses_duplicate_syllabus_headings_and_uses_current_names(): void
    {
        $this->withoutVite();

        [$grade, $chapter, $admin] = $this->seedGradeAndAdmin();
        $boardId = $chapter->syllabusVersion->board_id;

        $chapter->update([
            'chapter_number' => 'Ch 4',
            'name' => 'Expressions Using Letter-Numbers',
        ]);

        SyllabusChapter::query()->create([
            'syllabus_version_id' => $chapter->syllabus_version_id,
            'name' => 'Expressions Using Letter-Numbers',
            'chapter_number' => '4',
            'sort_order' => 2,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.content-tasks.create', ['board_id' => $boardId]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('syllabusChapters', 1)
                ->where('syllabusChapters.0.name', 'Expressions Using Letter-Numbers')
                ->where('syllabusChapters.0.label', fn ($label) => str_contains((string) $label, 'Expressions Using Letter-Numbers')));
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
