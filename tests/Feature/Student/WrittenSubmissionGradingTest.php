<?php

namespace Tests\Feature\Student;

use App\Mail\WrittenWorkCheckFailed;
use App\Mail\WrittenWorkGraded;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\FormulaDrillSession;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WrittenSubmission;
use App\Services\PdfPageImageService;
use App\Services\WrittenGradingService;
use App\Services\WrittenSubmissionService;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WrittenSubmissionGradingTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_grades_submission_after_response(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Good work.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '4',
                                        'step_feedback' => 'Correct.',
                                        'score' => 1,
                                        'is_correct' => true,
                                        'confidence' => 0.95,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();

        $service = app(WrittenSubmissionService::class);
        $file = UploadedFile::fake()->image('answer.jpg');

        $submission = $service->store($assignment, [$file]);
        $this->assertSame(WrittenSubmission::STATUS_UPLOADED, $submission->status);

        app()->terminate();

        $submission->refresh();
        $this->assertSame(WrittenSubmission::STATUS_GRADED, $submission->status);
        $this->assertSame(1, $submission->score);
        $this->assertSame(1, $submission->max_score);
    }

    public function test_grading_prompt_warns_against_minus_on_fractions_and_stores_source_page(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Good.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '1/2',
                                        'step_feedback' => 'Correct.',
                                        'score' => 1,
                                        'is_correct' => true,
                                        'confidence' => 0.9,
                                        'needs_review' => false,
                                        'source_page' => 1,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $service = app(WrittenSubmissionService::class);
        $submission = $service->store($assignment, [UploadedFile::fake()->image('answer.jpg')]);

        app(WrittenGradingService::class)->grade($submission->fresh());

        $request = Http::recorded()[0][0] ?? null;
        $this->assertNotNull($request);
        $body = $request->data();
        $prompt = data_get($body, 'messages.1.content.0.text');
        $this->assertIsString($prompt);
        $this->assertStringContainsString('Do not read 1/2 as -1/2', $prompt);
        $this->assertStringContainsString('source_page', $prompt);

        $item = $submission->fresh()->items()->first();
        $this->assertSame('1/2', $item->extracted_answer);
        $this->assertSame(1, $item->source_page);
        $this->assertNotNull($item->source_image_path);
        $this->assertNotNull($item->sourceImageUrl());
    }

    public function test_admin_can_re_read_one_question_without_wiping_others(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'summary' => 'Mixed.',
                                    'items' => [
                                        [
                                            'question_number' => 1,
                                            'extracted_answer' => '-1/2',
                                            'step_feedback' => 'Sign error?',
                                            'score' => 0,
                                            'is_correct' => false,
                                            'confidence' => 0.5,
                                            'needs_review' => true,
                                            'source_page' => 1,
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ])
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'summary' => 'Re-read Q1.',
                                    'items' => [
                                        [
                                            'question_number' => 1,
                                            'extracted_answer' => '1/2',
                                            'step_feedback' => 'Final answer is 1/2.',
                                            'score' => 1,
                                            'is_correct' => true,
                                            'confidence' => 0.95,
                                            'needs_review' => false,
                                            'source_page' => 1,
                                        ],
                                    ],
                                ]),
                            ],
                        ],
                    ],
                ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $service = app(WrittenSubmissionService::class);
        $submission = $service->store($assignment, [UploadedFile::fake()->image('answer.jpg')]);
        app(WrittenGradingService::class)->grade($submission->fresh());

        $this->assertSame('-1/2', $assignment->latestWrittenSubmission()->items()->first()->extracted_answer);

        $service->retryAiQuestion($assignment->latestWrittenSubmission(), 1);

        $item = $assignment->latestWrittenSubmission()->items()->first();
        $this->assertSame('1/2', $item->extracted_answer);
        $this->assertTrue($item->is_correct);
        $this->assertSame(1, (int) $assignment->latestWrittenSubmission()->score);
    }

    public function test_graded_payload_includes_correct_answer_and_allows_retry_upload(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Check Q1 again.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '5',
                                        'step_feedback' => 'Incorrect.',
                                        'score' => 0,
                                        'is_correct' => false,
                                        'confidence' => 0.8,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $service = app(WrittenSubmissionService::class);

        $submission = $service->store($assignment, [UploadedFile::fake()->image('first.jpg')]);
        app()->terminate();
        $submission->refresh();

        $this->assertSame(WrittenSubmission::STATUS_GRADED, $submission->status);
        $this->assertSame(SetAssignment::STATUS_COMPLETED, $assignment->fresh()->status);

        $payload = $service->payloadForAssignment($assignment->fresh());
        $this->assertTrue($payload['can_retry']);
        $this->assertNotEmpty($payload['upload_files']);
        $this->assertSame('image', $payload['upload_files'][0]['kind']);
        $this->assertSame('5', $payload['items'][0]['extracted_answer']);
        $this->assertSame('4', $payload['items'][0]['correct_answer']);
        $this->assertFalse($payload['items'][0]['is_correct']);

        $retry = $service->store($assignment->fresh(), [UploadedFile::fake()->image('retry.jpg')], [
            'schedule_ai' => false,
        ]);
        $this->assertSame(WrittenSubmission::STATUS_UPLOADED, $retry->status);
        $this->assertSame(SetAssignment::STATUS_IN_PROGRESS, $assignment->fresh()->status);
        $this->assertSame($submission->id, $retry->id);
    }

    public function test_admin_can_upload_revision_and_save_marks_again(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Checked.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '5',
                                        'step_feedback' => 'Incorrect.',
                                        'score' => 0,
                                        'is_correct' => false,
                                        'confidence' => 0.7,
                                        'needs_review' => true,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $questionId = $assignment->practiceSet->questions()->first()->id;
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        app(WrittenSubmissionService::class)->store($assignment, [UploadedFile::fake()->image('first.jpg')]);
        app()->terminate();

        $response = $this->actingAs($admin)->post(
            route('admin.written-assignments.upload-revision', $assignment),
            ['files' => [UploadedFile::fake()->image('revised.jpg')]],
        );
        $response->assertRedirect();

        $submission = WrittenSubmission::query()->where('set_assignment_id', $assignment->id)->first();
        $this->assertContains($submission->status, [
            WrittenSubmission::STATUS_UPLOADED,
            WrittenSubmission::STATUS_PROCESSING,
            WrittenSubmission::STATUS_GRADED,
        ]);
        $this->assertNotEmpty($submission->uploadFiles());
        $this->assertSame('image', $submission->uploadFiles()[0]['kind']);

        $graded = app(WrittenSubmissionService::class)->applyManualGrade($assignment->fresh(), [
            'handwriting_rating' => WrittenSubmission::HANDWRITING_GOOD,
            'remarks' => 'Revised scan is clearer.',
            'items' => [
                ['question_id' => $questionId, 'is_correct' => true],
            ],
        ]);

        $this->assertSame(WrittenSubmission::STATUS_GRADED, $graded->status);
        $this->assertSame(1, $graded->score);
        $this->assertSame('Revised scan is clearer.', $graded->teacher_remarks);
        $this->assertSame(WrittenSubmission::HANDWRITING_GOOD, $graded->handwriting_rating);
    }

    public function test_manual_override_keeps_extracted_answer(): void
    {
        [$assignment] = $this->seedWrittenAssignment();
        $questionId = $assignment->practiceSet->questions()->first()->id;

        $submission = WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_GRADED,
            'upload_paths' => [],
            'score' => 0,
            'max_score' => 1,
            'uploaded_at' => now(),
            'graded_at' => now(),
        ]);

        $submission->items()->create([
            'question_id' => $questionId,
            'question_number' => 1,
            'extracted_answer' => '4',
            'step_feedback' => 'Incorrect',
            'score' => 0,
            'max_score' => 1,
            'is_correct' => false,
        ]);

        $overridden = app(WrittenSubmissionService::class)->applyManualGrade($assignment, [
            'handwriting_rating' => WrittenSubmission::HANDWRITING_GOOD,
            'remarks' => 'Handwriting was clear — mark correct.',
            'items' => [
                ['question_id' => $questionId, 'is_correct' => true],
            ],
        ]);

        $this->assertTrue($overridden->items->first()->is_correct);
        $this->assertSame('4', $overridden->items->first()->extracted_answer);
        $this->assertSame(1, $overridden->score);
        $this->assertSame(WrittenSubmission::HANDWRITING_GOOD, $overridden->handwriting_rating);
        $this->assertSame('Handwriting was clear — mark correct.', $overridden->teacher_remarks);
    }

    public function test_teacher_can_apply_manual_grade_and_feedback(): void
    {
        [$assignment] = $this->seedWrittenAssignment();
        $questionId = $assignment->practiceSet->questions()->first()->id;

        $submission = app(WrittenSubmissionService::class)->applyManualGrade($assignment, [
            'handwriting_rating' => WrittenSubmission::HANDWRITING_VERY_GOOD,
            'remarks' => 'Revise fractions.',
            'items' => [
                ['question_id' => $questionId, 'is_correct' => true],
            ],
        ]);

        $this->assertSame(WrittenSubmission::STATUS_GRADED, $submission->status);
        $this->assertSame(1, $submission->score);
        $this->assertSame(1, $submission->max_score);
        $this->assertSame('Revise fractions.', $submission->teacher_remarks);
        $this->assertSame(WrittenSubmission::HANDWRITING_VERY_GOOD, $submission->handwriting_rating);
        $this->assertSame('Very good', $submission->handwritingLabel());
        $this->assertTrue($submission->items->first()->is_correct);
        $this->assertSame(SetAssignment::STATUS_COMPLETED, $assignment->fresh()->status);
    }

    public function test_manual_grade_calculates_score_from_question_ticks(): void
    {
        [$assignment] = $this->seedWrittenAssignment();
        $topic = $assignment->practiceSet->topic;
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $q2 = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => 'What is 3 + 1?',
            'source' => Question::SOURCE_MANUAL,
            'created_by' => $admin->id,
        ]);
        QuestionBlankAnswer::query()->create([
            'question_id' => $q2->id,
            'correct_answer' => '4',
            'answer_format' => 'integer',
        ]);
        $assignment->practiceSet->questions()->attach($q2->id, ['sort_order' => 2]);
        $assignment->load('practiceSet.questions');

        $q1Id = $assignment->practiceSet->questions->first()->id;

        $submission = app(WrittenSubmissionService::class)->applyManualGrade($assignment, [
            'handwriting_rating' => WrittenSubmission::HANDWRITING_OK,
            'items' => [
                ['question_id' => $q1Id, 'is_correct' => true],
                ['question_id' => $q2->id, 'is_correct' => false],
            ],
        ]);

        $this->assertSame(1, $submission->score);
        $this->assertSame(2, $submission->max_score);
        $this->assertSame(2, $submission->items->count());
    }

    public function test_weekly_summary_includes_manual_written_grade(): void
    {
        [$assignment] = $this->seedWrittenAssignment();
        $enrollment = $assignment->enrollment;
        $questionId = $assignment->practiceSet->questions()->first()->id;

        app(WrittenSubmissionService::class)->applyManualGrade($assignment, [
            'handwriting_rating' => WrittenSubmission::HANDWRITING_GOOD,
            'remarks' => 'Neat work.',
            'items' => [
                ['question_id' => $questionId, 'is_correct' => true],
            ],
        ]);

        $summary = app(\App\Services\StudentProgressSummaryService::class)->build($enrollment, now());

        $this->assertSame(1, $summary['stats']['completed_count']);
        $this->assertSame('C7-INT-ADD-P1-W', $summary['completed'][0]['set_code']);
        $this->assertSame(1, $summary['completed'][0]['latest_score']);
        $this->assertSame(1, $summary['completed'][0]['latest_max_score']);
        $labels = collect($summary['completed'][0]['review_items'])->pluck('label')->implode(' ');
        $this->assertStringContainsString('Handwriting — Good', $labels);
        $this->assertStringContainsString('Neat work.', $labels);
    }

    public function test_pdf_upload_is_converted_to_images_before_grading(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Checked PDF.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '4',
                                        'step_feedback' => 'Correct.',
                                        'score' => 1,
                                        'is_correct' => true,
                                        'confidence' => 0.9,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $pdfPath = 'written-submissions/'.$assignment->id.'/answers.pdf';
        Storage::disk('public')->put($pdfPath, '%PDF-1.4 fake');

        $pagePath = 'temp/written-grading/page-1.png';
        Storage::disk('public')->put($pagePath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $pages = Mockery::mock(PdfPageImageService::class);
        $pages->shouldReceive('isAvailable')->andReturn(true);
        $pages->shouldReceive('renderPages')
            ->once()
            ->with($pdfPath, Mockery::type('string'))
            ->andReturn([$pagePath]);
        $this->app->instance(PdfPageImageService::class, $pages);

        $submission = WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_UPLOADED,
            'upload_paths' => [$pdfPath],
            'uploaded_at' => now(),
        ]);

        app(WrittenGradingService::class)->grade($submission);

        $submission->refresh();
        $this->assertSame(WrittenSubmission::STATUS_GRADED, $submission->status);
        $this->assertSame(1, $submission->score);
    }

    public function test_pdf_upload_fails_clearly_without_ghostscript(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        [$assignment] = $this->seedWrittenAssignment();
        $pdfPath = 'written-submissions/'.$assignment->id.'/answers.pdf';
        Storage::disk('public')->put($pdfPath, '%PDF-1.4 fake');

        $pages = Mockery::mock(PdfPageImageService::class);
        $pages->shouldReceive('isAvailable')->andReturn(false);
        $this->app->instance(PdfPageImageService::class, $pages);

        $submission = WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_UPLOADED,
            'upload_paths' => [$pdfPath],
            'uploaded_at' => now(),
        ]);

        $ok = app(WrittenSubmissionService::class)->runGrading($submission->id);

        $this->assertFalse($ok);
        $submission->refresh();
        $this->assertSame(WrittenSubmission::STATUS_FAILED, $submission->status);
        $this->assertStringContainsString('Ghostscript', (string) $submission->grading_error);
    }

    public function test_admin_can_retry_failed_ai_grading(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Good work.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '4',
                                        'step_feedback' => 'Correct.',
                                        'score' => 1,
                                        'is_correct' => true,
                                        'confidence' => 0.95,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $path = 'written-submissions/'.$assignment->id.'/answers.jpg';
        Storage::disk('public')->put($path, 'image');

        $submission = WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_FAILED,
            'upload_paths' => [$path],
            'grading_error' => 'PDF answer sheets need Ghostscript on the server.',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.written-assignments.retry-ai', $assignment))
            ->assertRedirect();

        app()->terminate();

        $submission->refresh();
        $this->assertSame(WrittenSubmission::STATUS_GRADED, $submission->status);
        $this->assertNull($submission->grading_error);
        $this->assertSame(1, $submission->score);
    }

    public function test_grade_pending_command_processes_stuck_upload(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Done.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '4',
                                        'step_feedback' => 'Correct.',
                                        'score' => 1,
                                        'is_correct' => true,
                                        'confidence' => 0.9,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $path = 'written-submissions/'.$assignment->id.'/test.jpg';
        Storage::disk('public')->put($path, 'image');

        $submission = WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_UPLOADED,
            'upload_paths' => [$path],
            'uploaded_at' => now(),
        ]);

        $this->artisan('written-submissions:grade-pending')->assertSuccessful();

        $submission->refresh();
        $this->assertSame(WrittenSubmission::STATUS_GRADED, $submission->status);
    }

    public function test_admin_can_upload_seven_page_answer_sheet(): void
    {
        Storage::fake('public');

        [$assignment] = $this->seedWrittenAssignment();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $files = [];
        foreach (glob(base_path('tests/WhatsApp Image 2026-07-31*.jpeg')) ?: [] as $path) {
            $files[] = new UploadedFile($path, basename($path), 'image/jpeg', null, true);
        }

        $this->assertCount(7, $files);

        $this->actingAs($admin)->post(
            route('admin.written-assignments.upload-work', $assignment),
            [
                'files' => $files,
                'skip_ai' => true,
            ],
        )->assertRedirect();

        $submission = WrittenSubmission::query()->where('set_assignment_id', $assignment->id)->firstOrFail();
        $this->assertSame(WrittenSubmission::STATUS_UPLOADED, $submission->status);
        $this->assertCount(7, $submission->upload_paths ?? []);
        $this->assertCount(7, $submission->uploadFiles());
    }

    public function test_admin_can_upload_work_without_ai_and_save_marks(): void
    {
        Storage::fake('public');

        [$assignment] = $this->seedWrittenAssignment();
        $questionId = $assignment->practiceSet->questions()->first()->id;
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post(
            route('admin.written-assignments.upload-work', $assignment),
            [
                'files' => [UploadedFile::fake()->image('scan.jpg')],
                'skip_ai' => true,
            ],
        )->assertRedirect();

        $submission = WrittenSubmission::query()->where('set_assignment_id', $assignment->id)->firstOrFail();
        $this->assertSame(WrittenSubmission::STATUS_UPLOADED, $submission->status);
        $this->assertNotEmpty($submission->uploadFiles());

        $graded = app(WrittenSubmissionService::class)->applyManualGrade($assignment->fresh(), [
            'handwriting_rating' => WrittenSubmission::HANDWRITING_GOOD,
            'remarks' => 'Marked from admin upload.',
            'items' => [
                ['question_id' => $questionId, 'is_correct' => true],
            ],
        ]);

        $this->assertSame(WrittenSubmission::STATUS_GRADED, $graded->status);
        $this->assertSame(1, $graded->score);
    }

    public function test_admin_can_append_pages_to_partial_student_upload(): void
    {
        Storage::fake('public');

        [$assignment] = $this->seedWrittenAssignment();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $existingPath = 'written-submissions/'.$assignment->id.'/page1.jpg';
        Storage::disk('public')->put($existingPath, 'image-one');

        WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_GRADED,
            'upload_paths' => [$existingPath],
            'score' => 1,
            'max_score' => 1,
            'uploaded_at' => now(),
            'graded_at' => now(),
        ]);

        $this->actingAs($admin)->post(
            route('admin.written-assignments.upload-work', $assignment),
            [
                'files' => [UploadedFile::fake()->image('page2.jpg')],
                'append' => true,
                'skip_ai' => true,
            ],
        )->assertRedirect();

        $submission = WrittenSubmission::query()->where('set_assignment_id', $assignment->id)->firstOrFail();
        $this->assertSame(WrittenSubmission::STATUS_UPLOADED, $submission->status);
        $this->assertCount(2, $submission->upload_paths ?? []);
        Storage::disk('public')->assertExists($existingPath);
    }

    public function test_stale_processing_is_marked_failed_after_fifteen_minutes(): void
    {
        Mail::fake();
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        [$assignment] = $this->seedWrittenAssignment();
        $assignment->enrollment->student->update(['email' => 'student@example.com']);
        $path = 'written-submissions/'.$assignment->id.'/test.jpg';
        Storage::disk('public')->put($path, 'image');

        $submission = WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_PROCESSING,
            'upload_paths' => [$path],
            'uploaded_at' => now()->subMinutes(20),
        ]);
        $submission->updated_at = now()->subMinutes(16);
        $submission->saveQuietly();

        $ok = app(WrittenSubmissionService::class)->runGrading($submission->id);

        $this->assertFalse($ok);
        $submission->refresh();
        $this->assertSame(WrittenSubmission::STATUS_FAILED, $submission->status);
        $this->assertStringContainsString('teacher can mark', (string) $submission->grading_error);
        Mail::assertSent(WrittenWorkCheckFailed::class);
    }

    public function test_ai_grading_sends_result_email_to_student(): void
    {
        Mail::fake();
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Good work.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '4',
                                        'step_feedback' => 'Correct.',
                                        'score' => 1,
                                        'is_correct' => true,
                                        'confidence' => 0.95,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment] = $this->seedWrittenAssignment();
        $assignment->enrollment->student->update(['email' => 'student@example.com']);

        $submission = app(WrittenSubmissionService::class)->store(
            $assignment,
            [UploadedFile::fake()->image('answer.jpg')],
            ['schedule_ai' => false],
        );

        app(WrittenSubmissionService::class)->runGrading($submission->id);

        Mail::assertSent(WrittenWorkGraded::class, function (WrittenWorkGraded $mail) {
            return $mail->hasTo('student@example.com')
                && $mail->summary['set_code'] === 'C7-INT-ADD-P1-W'
                && str_contains((string) $mail->summary['score_label'], '1/1');
        });
    }

    public function test_student_upload_redirects_to_dashboard_with_message(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Done.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '4',
                                        'step_feedback' => 'Correct.',
                                        'score' => 1,
                                        'is_correct' => true,
                                        'confidence' => 0.9,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment, $studentUser] = $this->seedWrittenAssignment();
        $student = $assignment->enrollment->student;

        FormulaDrillSession::query()->create([
            'student_id' => $student->id,
            'drill_date' => now(config('formula_drill.timezone', 'Asia/Kolkata'))->startOfDay(),
            'status' => FormulaDrillSession::STATUS_COMPLETED,
            'questions_total' => 1,
            'questions_completed' => 1,
            'pool_size' => 1,
            'completed_at' => now(),
        ]);

        $this->actingAs($studentUser)->post(
            route('student.written-assignments.upload', $assignment),
            ['files' => [UploadedFile::fake()->image('answer.jpg')]],
        )
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');
    }

    public function test_student_can_reupload_after_graded_result(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Check order.',
                                'items' => [
                                    [
                                        'question_number' => 1,
                                        'extracted_answer' => '5',
                                        'step_feedback' => 'Incorrect.',
                                        'score' => 0,
                                        'is_correct' => false,
                                        'confidence' => 0.8,
                                        'needs_review' => false,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$assignment, $studentUser] = $this->seedWrittenAssignment();
        $student = $assignment->enrollment->student;

        FormulaDrillSession::query()->create([
            'student_id' => $student->id,
            'drill_date' => now(config('formula_drill.timezone', 'Asia/Kolkata'))->startOfDay(),
            'status' => FormulaDrillSession::STATUS_COMPLETED,
            'questions_total' => 1,
            'questions_completed' => 1,
            'pool_size' => 1,
            'completed_at' => now(),
        ]);

        app(WrittenSubmissionService::class)->store(
            $assignment,
            [UploadedFile::fake()->image('first.jpg')],
            ['schedule_ai' => false],
        );
        app(WrittenSubmissionService::class)->runGrading(
            WrittenSubmission::query()->where('set_assignment_id', $assignment->id)->firstOrFail()->id,
        );

        $this->actingAs($studentUser)->post(
            route('student.written-assignments.upload', $assignment),
            ['files' => [UploadedFile::fake()->image('reordered.jpg')]],
        )
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas(
                'success',
                'Re-upload received. Write answers in Q1, Q2, Q3… order on your sheet and upload photos in page order. We will email you when checking is finished.',
            );

        $submission = WrittenSubmission::query()->where('set_assignment_id', $assignment->id)->firstOrFail();
        $this->assertContains($submission->status, [
            WrittenSubmission::STATUS_UPLOADED,
            WrittenSubmission::STATUS_PROCESSING,
            WrittenSubmission::STATUS_GRADED,
        ]);
        $this->assertNotEmpty($submission->upload_paths);
    }

    /**
     * @return array{0: SetAssignment, 1: User}
     */
    private function seedWrittenAssignment(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => 'What is 2 + 2?',
            'source' => Question::SOURCE_MANUAL,
        ]);

        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'correct_answer' => '4',
            'answer_format' => 'integer',
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Practice — Written',
            'set_number' => 1,
            'set_code' => 'C7-INT-ADD-P1-W',
            'tier' => 'starter',
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => WrittenSheetStatus::VERIFIED,
            'written_pdf_path' => 'written-sheets/1/test.pdf',
            'created_by' => $admin->id,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'name' => 'Test Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        return [$assignment, $studentUser];
    }
}
