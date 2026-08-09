<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentUploaderPaymentMail;
use App\Models\AcademicYear;
use App\Models\Board;
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

    public function test_finance_index_lists_unpaid_published_tasks_and_total(): void
    {
        $this->withoutVite();

        [$admin, $uploader, $task] = $this->seedPayableTask();

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Finance/Index')
                ->has('unpaid', 1)
                ->where('unpaid.0.id', $task->id)
                ->where('unpaid.0.amount_inr', 100)
                ->where('unpaid_total_inr', 100)
                ->has('payments', 0));
    }

    public function test_admin_can_record_upi_payment_and_email_uploader(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$admin, $uploader, $task] = $this->seedPayableTask();

        $this->actingAs($admin)
            ->post(route('admin.finance.payments.store'), [
                'content_upload_task_id' => $task->id,
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
        $this->assertNotNull($payment->emailed_at);

        Mail::assertSent(ContentUploaderPaymentMail::class, fn ($mail) => $mail->hasTo($uploader->email));

        $this->actingAs($admin)
            ->get(route('admin.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('unpaid', 0)
                ->where('unpaid_total_inr', 0)
                ->has('payments', 1)
                ->where('payments.0.upi_or_reference', 'UPI123456'));
    }

    public function test_cannot_pay_same_task_twice(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$admin, , $task] = $this->seedPayableTask();

        $this->actingAs($admin)
            ->post(route('admin.finance.payments.store'), [
                'content_upload_task_id' => $task->id,
                'paid_on' => now()->toDateString(),
                'method' => 'upi',
                'upi_or_reference' => 'UPI-A',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->post(route('admin.finance.payments.store'), [
                'content_upload_task_id' => $task->id,
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
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 5', 'sort_order' => 5, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Fractions',
            'chapter_number' => 'Ch 2',
            'sort_order' => 2,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $uploader = User::factory()->create([
            'role' => User::ROLE_TEACHER,
            'email' => 'uploader-pay@example.com',
            'name' => 'Pay Mentor',
        ]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Math-Mela',
            'code' => 'MM',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Fractions',
            'pdf_path' => null,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

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
}
