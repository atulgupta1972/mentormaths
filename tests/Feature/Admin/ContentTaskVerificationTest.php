<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentTaskReturnedUploader;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationRun;
use App\Models\GradeLevel;
use App\Models\QuestionOption;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentTaskVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_edit_mcq_options_during_verification(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($uploader)
            ->post(route('content.tasks.mark-uploaded', $task))
            ->assertRedirect();

        $task->update(['status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH, 'submitted_at' => now()]);

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;
        $options = QuestionOption::query()->where('question_id', $questionId)->orderBy('sort_order')->get();

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('verification.questions', 1)
                ->has('verification.set_plan')
                ->where('verification.set_plan_parts', 1));

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-question', $task), [
                'run_id' => $runId,
                'question_id' => $questionId,
                'question_text' => 'What is two plus two?',
                'explanation' => 'Answer is C.',
                'method_hint' => 'Add',
                'difficulty' => 'Easy',
                'options' => $options->map(fn (QuestionOption $option, int $index) => [
                    'id' => $option->id,
                    'option_text' => $option->option_text,
                    'is_correct' => $index === 2,
                ])->values()->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(
            QuestionOption::query()
                ->where('question_id', $questionId)
                ->where('is_correct', true)
                ->where('sort_order', 3)
                ->exists()
        );
    }

    public function test_skipping_question_excludes_it_from_uploader_pay(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'rate_basis' => \App\Models\ContentRateCard::BASIS_PER_QUESTION,
            'agreed_amount_inr' => 10,
            'offered_amount_inr' => 10,
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->assertSame(1, $task->fresh()->uploadedQuestionCount());
        $this->assertSame(10, $task->fresh()->payableAmountInr());

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-skip', $task), [
                'run_id' => $runId,
                'question_id' => $questionId,
                'skip_reason' => 'Irrelevant practice sum',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $check = \App\Models\ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->where('question_id', $questionId)
            ->firstOrFail();

        $this->assertTrue($check->skipped);
        $this->assertTrue($check->isComplete());
        $this->assertFalse($check->countsForPay());

        $task = $task->fresh();
        $this->assertSame(1, $task->skippedQuestionCount());
        $this->assertSame(0, $task->payableQuestionCount());
        $this->assertSame(0, $task->payableAmountInr());
        $this->assertStringContainsString('skipped', strtolower($task->calculationLabel()));
    }

    public function test_ai_review_auto_approves_high_confidence_ok_questions(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;

        config(['services.openai.api_key' => 'test-key']);

        \Illuminate\Support\Facades\Http::fake([
            'api.openai.com/*' => \Illuminate\Support\Facades\Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'items' => [[
                                'question_id' => $questionId,
                                'verdict' => 'approve',
                                'confidence' => 'high',
                                'note' => 'Correct MCQ',
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ],
                ]],
            ]),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-ai-review', $task), [
                'run_id' => $runId,
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('ai_review');

        $check = \App\Models\ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->where('question_id', $questionId)
            ->firstOrFail();

        $this->assertTrue($check->isComplete());
        $this->assertFalse($check->skipped);
        $this->assertSame('approve', $check->ai_verdict);
        $this->assertSame('high', $check->ai_confidence);
    }

    public function test_admin_verification_shows_uploader_chapter_breakup(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $chapter->update([
            'mcq_set_plan' => [
                [
                    'set_code' => 'GP-C1-P1',
                    'q_from' => 1,
                    'q_to' => 1,
                    'description' => 'Patterns intro',
                ],
            ],
        ]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ContentTasks/Show')
                ->where('verification.set_plan_parts', 1)
                ->where('verification.set_plan.0.set_code', 'GP-C1-P1')
                ->where('verification.set_plan.0.description', 'Patterns intro')
                ->where('verification.set_plan_summary', fn ($summary) => str_contains((string) $summary, 'GP-C1-P1')));
    }

    public function test_admin_can_send_task_back_for_reverification(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$uploader, , $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.return-for-reverification', $task), [
                'reason' => 'All answers are option A — please fix.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS, $task->status);
        $this->assertNull($task->submitted_at);
        $this->assertStringContainsString('All answers are option A', (string) $task->admin_notes);

        Mail::assertSent(ContentTaskReturnedUploader::class, fn ($mail) => $mail->hasTo($uploader->email));
    }

    public function test_admin_can_send_published_task_back_without_deleting_questions(): void
    {
        Mail::fake();
        $this->withoutVite();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $publishedAt = now()->subDay();

        $task->update([
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'published_at' => $publishedAt,
            'published_by' => $admin->id,
        ]);

        $worksheetIds = $chapter->fresh()->mcqWorksheetIds();
        $this->assertNotEmpty($worksheetIds);

        $this->actingAs($admin)
            ->from(route('dashboard'))
            ->post(route('admin.content-tasks.return-for-reverification', $task), [
                'reason' => 'Please re-check every question. Do not delete any.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS, $task->status);
        $this->assertNotNull($task->published_at);
        $this->assertTrue($task->isLockedForUploaderDelete());
        $this->assertSame($worksheetIds, $chapter->fresh()->mcqWorksheetIds());
        $this->assertSame(1, Worksheet::query()->findOrFail($worksheetIds[0])->questions()->count());

        $this->actingAs($uploader)
            ->from(route('content.chapters.show', $chapter))
            ->post(route('content.chapters.delete-question', $chapter), ['item_index' => 0])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, count($chapter->fresh()->extraction_items ?? []));
        Mail::assertSent(ContentTaskReturnedUploader::class, fn ($mail) => $mail->hasTo($uploader->email));
    }

    public function test_admin_can_batch_mark_questions_verified(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-batch', $task), [
                'run_id' => $runId,
                'question_ids' => [$questionId],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $check = \App\Models\ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->where('question_id', $questionId)
            ->first();

        $this->assertNotNull($check);
        $this->assertTrue($check->isComplete());

        // Single-question chapter → verifying finishes and becomes verified (then publishable).
        // Seed starts as submitted_for_publish; leave that status alone when already submitted.
        $task->refresh();
        $this->assertTrue(in_array($task->status, [
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
        ], true));
    }

    public function test_admin_batch_verify_moves_verifying_task_to_verified_and_can_publish(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update(['status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('task.can_publish', false));

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-batch', $task), [
                'run_id' => $runId,
                'question_ids' => [$questionId],
            ])
            ->assertRedirect();

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_VERIFIED, $task->status);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('task.can_publish', true));

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.publish', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(ContentUploadTask::STATUS_PUBLISHED, $task->fresh()->status);
    }

    public function test_admin_can_return_selected_questions_with_remarks_in_one_email(): void
    {
        Mail::fake();
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk();

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-batch', $task), [
                'run_id' => $runId,
                'question_ids' => [$questionId],
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.return-for-reverification', $task), [
                'reason' => 'Please fix figures where needed.',
                'items' => [[
                    'question_id' => $questionId,
                    'number' => 1,
                    'remark' => 'Needs figure upload',
                    'question_text' => 'What is 2 + 2?',
                ]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $check = \App\Models\ContentVerificationCheck::query()
            ->where('content_verification_run_id', $runId)
            ->where('question_id', $questionId)
            ->first();

        $this->assertFalse($check->isComplete());
        $this->assertStringContainsString('Needs figure upload', (string) $task->fresh()->admin_notes);

        Mail::assertSent(ContentTaskReturnedUploader::class, function (ContentTaskReturnedUploader $mail) use ($uploader) {
            return $mail->hasTo($uploader->email)
                && count($mail->returnItems) === 1
                && $mail->returnItems[0]['remark'] === 'Needs figure upload';
        });
    }

    public function test_start_review_marks_uploaded_and_opens_verification(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $this->actingAs($uploader)
            ->post(route('content.tasks.start-review', $task))
            ->assertRedirect(route('content.tasks.show', $task))
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_UPLOADED, $task->status);

        $this->actingAs($uploader)
            ->get(route('content.tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('verification.questions', 1));

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS, $task->status);
    }

    public function test_uploader_can_upload_figure_during_verification_review(): void
    {
        $this->withoutVite();
        \Illuminate\Support\Facades\Storage::fake('public');

        [$uploader, $chapter, $task] = $this->seedPublishedTask();

        $this->actingAs($uploader)
            ->post(route('content.tasks.start-review', $task))
            ->assertRedirect(route('content.tasks.show', $task));

        $this->actingAs($uploader)
            ->get(route('content.tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('verification.run_id'));

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->value('id');

        $this->assertNotNull($runId);

        $question = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail();

        $this->actingAs($uploader)
            ->post(route('content.tasks.verification-diagram', $task), [
                'run_id' => $runId,
                'question_id' => $question->id,
                'diagram' => \Illuminate\Http\UploadedFile::fake()->image('figure.png', 120, 80),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $question->refresh();
        $this->assertNotNull($question->diagram_path);
        $this->assertNotNull($question->diagram_url);
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2: ContentUploadTask}
     */
    private function seedPublishedTask(): array
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

        $json = json_encode([
            'questions' => [[
                'topic' => 'Addition',
                'question' => 'What is 2 + 2?',
                'options' => ['3', '4', '5', '6'],
                'correct_index' => 0,
                'hint' => 'Add',
                'explanation' => 'Wrong on purpose',
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

    public function test_admin_can_apply_gemini_paste_to_auto_verify_correct_questions(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedPublishedTask();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        $questionId = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail()->id;

        $this->actingAs($admin)
            ->get(route('admin.content-tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.summary.unverified', 1)
                ->has('verification.gemini_prompt'));

        $runId = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $admin->id)
            ->value('id');

        $paste = <<<'TEXT'
Question 1 Analysis:
Status: Correct
Note: 2 + 2 equals 4.
TEXT;

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.verification-gemini-paste', $task), [
                'run_id' => $runId,
                'gemini_paste' => $paste,
            ])
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionHas('gemini_review.approved', 1);

        $this->assertTrue(
            \App\Models\ContentVerificationCheck::query()
                ->where('content_verification_run_id', $runId)
                ->where('question_id', $questionId)
                ->whereNotNull('verified_at')
                ->exists()
        );
    }
}
