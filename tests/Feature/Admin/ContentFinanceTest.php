<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentUploaderBatchPaymentMail;
use App\Mail\ContentUploaderPaymentMail;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentRateCard;
use App\Models\ContentUploadTask;
use App\Models\ContentUploaderPayment;
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

class ContentFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_index_lists_unpaid_grouped_by_uploader(): void
    {
        $this->withoutVite();

        [$admin, $uploader, $task] = $this->seedPayableTask();

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Finance/Index')
                ->has('unpaid_groups', 1)
                ->where('unpaid_groups.0.assignee.id', $uploader->id)
                ->where('unpaid_groups.0.total_inr', 100)
                ->where('unpaid_groups.0.task_count', 1)
                ->has('unpaid_groups.0.tasks', 1)
                ->where('unpaid_total_inr', 100)
                ->where('unpaid_chapter_count', 1)
                ->has('payment_groups', 0));
    }

    public function test_finance_shows_per_question_rate_and_calculation(): void
    {
        $this->withoutVite();

        [$admin, $uploader, , , , , $chapter] = $this->seedBase(chapterNumber: 4);

        $chapter->update([
            'extraction_items' => [
                ['question' => 'Q1'],
                ['question' => 'Q2'],
                ['question' => 'Q3'],
            ],
        ]);

        ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'rate_basis' => ContentRateCard::BASIS_PER_QUESTION,
            'offered_amount_inr' => 2,
            'agreed_amount_inr' => 2,
            'agreed_at' => now(),
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Finance/Index')
                ->where('unpaid_groups.0.total_inr', 6)
                ->where('unpaid_groups.0.tasks.0.rate_unit_inr', 2)
                ->where('unpaid_groups.0.tasks.0.question_count', 3)
                ->where('unpaid_groups.0.tasks.0.rate_agreed_label', '₹2 per question')
                ->where('unpaid_groups.0.tasks.0.calculation_label', '3 questions × ₹2 = ₹6')
                ->where('unpaid_total_inr', 6));
    }

    public function test_admin_can_record_upi_payment_and_email_uploader(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$admin, $uploader, $task] = $this->seedPayableTask();

        $this->actingAs($admin)
            ->post(route('admin.finance.payments.store'), [
                'content_upload_task_ids' => [$task->id],
                'paid_on' => now()->toDateString(),
                'method' => 'upi',
                'upi_or_reference' => 'UPI123456',
                'notes' => 'Paid for Ch 2',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment = ContentUploaderPayment::query()->where('content_upload_task_id', $task->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame(100, $payment->amount_inr);
        $this->assertSame('UPI123456', $payment->upi_or_reference);
        $this->assertNotNull($payment->batch_id);
        $this->assertNotNull($payment->emailed_at);

        Mail::assertSent(ContentUploaderPaymentMail::class, fn ($mail) => $mail->hasTo($uploader->email));

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('unpaid_groups', 0)
                ->where('unpaid_total_inr', 0)
                ->has('payment_groups', 1)
                ->where('payment_groups.0.upi_or_reference', 'UPI123456')
                ->where('payment_groups.0.total_inr', 100));
    }

    public function test_admin_can_record_combined_batch_payment_for_one_uploader(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$admin, $uploader, $taskOne, $taskTwo] = $this->seedTwoPayableTasksSameUploader();

        $this->actingAs($admin)
            ->post(route('admin.finance.payments.store'), [
                'content_upload_task_ids' => [$taskOne->id, $taskTwo->id],
                'paid_on' => now()->toDateString(),
                'method' => 'upi',
                'upi_or_reference' => 'UPI-BATCH-99',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payments = ContentUploaderPayment::query()->orderBy('id')->get();
        $this->assertCount(2, $payments);
        $this->assertSame('UPI-BATCH-99', $payments[0]->upi_or_reference);
        $this->assertSame($payments[0]->batch_id, $payments[1]->batch_id);
        $this->assertSame(200, $payments->sum('amount_inr'));

        Mail::assertSent(ContentUploaderBatchPaymentMail::class, fn ($mail) => $mail->hasTo($uploader->email));

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('unpaid_groups', 0)
                ->has('payment_groups', 1)
                ->where('payment_groups.0.chapter_count', 2)
                ->where('payment_groups.0.total_inr', 200));
    }

    public function test_cannot_pay_same_task_twice(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$admin, , $task] = $this->seedPayableTask();

        $this->actingAs($admin)
            ->post(route('admin.finance.payments.store'), [
                'content_upload_task_ids' => [$task->id],
                'paid_on' => now()->toDateString(),
                'method' => 'upi',
                'upi_or_reference' => 'UPI-A',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('admin.finance.payments.store'), [
                'content_upload_task_ids' => [$task->id],
                'paid_on' => now()->toDateString(),
                'method' => 'upi',
                'upi_or_reference' => 'UPI-B',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, ContentUploaderPayment::query()->count());
    }

    /**
     * @return array{0: User, 1: User, 2: ContentUploadTask}
     */
    private function seedPayableTask(): array
    {
        [$admin, $uploader, , , , , $chapter] = $this->seedBase();

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 100,
            'agreed_amount_inr' => 100,
            'agreed_at' => now(),
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        return [$admin, $uploader, $task];
    }

    /**
     * @return array{0: User, 1: User, 2: ContentUploadTask, 3: ContentUploadTask}
     */
    private function seedTwoPayableTasksSameUploader(): array
    {
        [$admin, $uploader, , , , , $chapterOne] = $this->seedBase(chapterNumber: 2);
        [, , , , , , $chapterTwo] = $this->seedBase(chapterNumber: 3, reuse: compact('admin', 'uploader'));

        $taskOne = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapterOne->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 100,
            'agreed_amount_inr' => 100,
            'agreed_at' => now(),
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        $taskTwo = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapterTwo->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 100,
            'agreed_amount_inr' => 100,
            'agreed_at' => now(),
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        return [$admin, $uploader, $taskOne, $taskTwo];
    }

    /**
     * @param  array{admin?: User, uploader?: User}|null  $reuse
     * @return array{0: User, 1: User, 2: SyllabusVersion, 3: GradeLevel, 4: Textbook, 5: SyllabusChapter, 6: TextbookChapter}
     */
    private function seedBase(int $chapterNumber = 2, ?array $reuse = null): array
    {
        $year = AcademicYear::query()->firstOrCreate(
            ['name' => '2026-27'],
            ['starts_on' => '2026-03-01', 'ends_on' => '2027-02-28', 'is_active' => true],
        );

        $board = Board::query()->firstOrCreate(
            ['code' => 'CBSE'],
            ['name' => 'CBSE', 'is_active' => true],
        );
        $grade = GradeLevel::query()->firstOrCreate(
            ['name' => 'Class 5'],
            ['sort_order' => 5, 'is_active' => true],
        );
        $subject = Subject::query()->firstOrCreate(
            ['code' => 'MATHS'],
            ['name' => 'Mathematics'],
        );

        $syllabus = SyllabusVersion::query()->firstOrCreate([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $admin = $reuse['admin'] ?? User::factory()->create(['role' => User::ROLE_ADMIN]);
        $uploader = $reuse['uploader'] ?? tap(User::factory()->create([
            'role' => User::ROLE_TEACHER,
            'email' => 'uploader-pay@example.com',
            'name' => 'Pay Mentor',
        ]), fn (User $user) => app(UserGroupService::class)->attachGroupByCode($user, User::ROLE_CONTENT_UPLOADER));

        $textbook = Textbook::query()->firstOrCreate(
            ['grade_level_id' => $grade->id, 'code' => 'MM'],
            ['name' => 'Math-Mela', 'is_active' => true, 'created_by' => $admin->id],
        );

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => "Topic {$chapterNumber}",
            'chapter_number' => "Ch {$chapterNumber}",
            'sort_order' => $chapterNumber,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => $chapterNumber,
            'title' => "Chapter {$chapterNumber}",
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        return [$admin, $uploader, $syllabus, $grade, $textbook, $syllabusChapter, $chapter];
    }
}
