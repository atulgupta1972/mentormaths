<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentTaskAssignedUploader;
use App\Models\ContentUploadTask;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\TextbookChapterPublishService;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FillBlankConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_assigns_conversion_and_uploader_must_check_as_student_before_submit(): void
    {
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $mcqChapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => 'Data handling',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'question_text' => 'What is 40 + 5?',
                'correct_answer' => '45',
                'label' => 'Mean',
            ]],
        ]);

        ContentUploadTask::create([
            'textbook_chapter_id' => $mcqChapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_MCQ_UPLOAD,
            'assigned_to_user_id' => $admin->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.assign-fill-blank-conversion'), [
                'textbook_chapter_id' => $mcqChapter->id,
                'assigned_to_user_id' => $uploader->id,
                'offered_amount_inr' => 25,
            ])
            ->assertRedirect();

        $task = ContentUploadTask::query()
            ->where('work_type', ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION)
            ->firstOrFail();

        $this->assertSame($uploader->id, $task->assigned_to_user_id);
        $this->assertSame(25, $task->offered_amount_inr);
        Mail::assertSent(ContentTaskAssignedUploader::class, fn ($mail) => $mail->hasTo($uploader->email));

        $this->actingAs($uploader)
            ->post(route('content.tasks.agree', $task))
            ->assertRedirect(route('content.tasks.convert', $task));

        $this->actingAs($uploader)
            ->post(route('content.tasks.submit-for-publish', $task))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($uploader)
            ->post(route('content.tasks.convert-check', $task), [
                'index' => 0,
                'fill_blank_question_text' => 'What is 40 + 5? The answer is ____.',
                'fill_blank_correct_answer' => '45',
                'fill_blank_answer_format' => 'integer',
                'attempt' => '45',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($uploader)
            ->post(route('content.tasks.submit-for-publish', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH, $task->status);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.publish', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $task->refresh();
        $mcqChapter->refresh();
        $this->assertSame(ContentUploadTask::STATUS_PUBLISHED, $task->status);
        $this->assertNotEmpty($mcqChapter->fillBlankWorksheetIds());
        $this->assertNotEmpty($mcqChapter->writtenWorksheetIds());
    }

    public function test_uploader_can_delete_non_numeric_answers_from_conversion(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 3,
            'title' => 'Words',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [
                [
                    'question_text' => 'What is forty plus five?',
                    'correct_answer' => 'forty five',
                    'label' => 'Words',
                ],
                [
                    'question_text' => 'What is 2+2?',
                    'correct_answer' => '4',
                    'label' => 'Numbers',
                ],
            ],
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $task = ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 20,
            'agreed_amount_inr' => 20,
            'agreed_at' => now(),
        ]);

        $this->actingAs($uploader)
            ->get(route('content.tasks.convert', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Tasks/FillBlankConvert')
                ->where('rows.0.non_numeric_answer', true)
                ->where('rows.1.non_numeric_answer', false));

        $this->actingAs($uploader)
            ->post(route('content.tasks.convert-clear', $task), [
                'indexes' => [0],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertTrue((bool) ($chapter->extraction_items[0]['fill_blank_skipped'] ?? false));
        $this->assertSame('4', $chapter->extraction_items[1]['correct_answer']);
        $this->assertCount(2, $chapter->extraction_items);
    }

    public function test_uploader_can_remove_conversion_rows_missing_figures(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Integers',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [
                [
                    'question_text' => 'Madhre stands on a bridge. Vertical distance is ____ metres.',
                    'correct_answer' => '55',
                    'needs_diagram' => true,
                    'diagram_file' => 'chart1.png',
                    'fill_blank_question_text' => 'Vertical distance is ____ metres.',
                    'fill_blank_correct_answer' => '55',
                    'fill_blank_answer_format' => 'integer',
                ],
                [
                    'question_text' => '[(–10) × (+9)] + (–10) = ____',
                    'correct_answer' => '–100',
                    'needs_diagram' => false,
                    'fill_blank_question_text' => '[(–10) × (+9)] + (–10) = ____',
                    'fill_blank_correct_answer' => '–100',
                    'fill_blank_answer_format' => 'integer',
                ],
            ],
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $task = ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 20,
            'agreed_amount_inr' => 20,
            'agreed_at' => now(),
        ]);

        $this->actingAs($uploader)
            ->get(route('content.tasks.convert', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Tasks/FillBlankConvert')
                ->where('rows.0.missing_diagram', true)
                ->where('rows.1.missing_diagram', false)
                ->where('progress.missing_diagram', 1));

        $this->actingAs($uploader)
            ->post(route('content.tasks.convert-clear-missing-diagrams', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertTrue((bool) ($chapter->extraction_items[0]['fill_blank_skipped'] ?? false));
        $this->assertFalse((bool) ($chapter->extraction_items[1]['fill_blank_skipped'] ?? false));
    }

    public function test_admin_can_clear_partial_and_all_conversion_rows(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Numbers',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [
                [
                    'question_text' => 'What is 2+2?',
                    'correct_answer' => '4',
                    'fill_blank_question_text' => '2+2 = ____.',
                    'fill_blank_correct_answer' => 'four',
                    'fill_blank_answer_format' => 'text',
                    'include_in_fill_blank' => true,
                ],
                [
                    'question_text' => 'What is 3+3?',
                    'correct_answer' => '6',
                    'fill_blank_question_text' => '3+3 = ____.',
                    'fill_blank_correct_answer' => '6',
                    'fill_blank_answer_format' => 'integer',
                    'include_in_fill_blank' => true,
                ],
            ],
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $task = ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'offered_amount_inr' => 20,
            'agreed_amount_inr' => 20,
            'agreed_at' => now(),
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.conversion-clear-rows', $task), [
                'indexes' => [0],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertTrue((bool) ($chapter->extraction_items[0]['fill_blank_skipped'] ?? false));
        $this->assertArrayNotHasKey('fill_blank_correct_answer', $chapter->extraction_items[0]);
        $this->assertSame('6', $chapter->extraction_items[1]['fill_blank_correct_answer']);

        $this->actingAs($admin)
            ->post(route('admin.content-tasks.conversion-clear-all', $task))
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertTrue((bool) ($chapter->extraction_items[1]['fill_blank_skipped'] ?? false));
        $this->assertArrayNotHasKey('fill_blank_correct_answer', $chapter->extraction_items[1]);
    }

    public function test_wrong_student_attempt_does_not_mark_checked(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Numbers',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'question_text' => 'Ten ones make ____.',
                'correct_answer' => '10',
            ]],
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $task = ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 20,
            'agreed_amount_inr' => 20,
            'agreed_at' => now(),
        ]);

        $this->actingAs($uploader)
            ->post(route('content.tasks.convert-check', $task), [
                'index' => 0,
                'fill_blank_question_text' => 'Ten ones make ____.',
                'fill_blank_correct_answer' => '10',
                'fill_blank_answer_format' => 'integer',
                'attempt' => 'ten',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $chapter->refresh();
        $this->assertNull($chapter->extraction_items[0]['fill_blank_checked_at'] ?? null);
    }

    public function test_gemini_preview_and_apply_splits_convertible_and_mcq_only_rows(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Puzzles',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [
                [
                    'question_text' => 'What is 40 + 5?',
                    'correct_answer' => '45',
                    'label' => 'Sum',
                ],
                [
                    'question_text' => 'Find two numbers whose sum is 25 and difference is 11.',
                    'correct_answer' => '18 and 7',
                    'label' => 'Word puzzle',
                ],
                [
                    'question_text' => 'Shaded part as fraction?',
                    'correct_answer' => '1 1/2',
                    'label' => 'Mixed',
                ],
            ],
        ]);

        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $task = ContentUploadTask::create([
            'textbook_chapter_id' => $chapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 30,
            'agreed_amount_inr' => 30,
            'agreed_at' => now(),
        ]);

        $json = json_encode([
            'questions' => [[
                'source_index' => 1,
                'question' => 'What is 40 + 5? The answer is ____.',
                'answer_format' => 'integer',
                'correct_answer' => '45',
                'explanation' => '40 + 5 = 45.',
                'method_hint' => 'Add.',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($uploader)
            ->post(route('content.tasks.convert-gemini-preview', $task), ['json' => $json])
            ->assertRedirect()
            ->assertSessionHas('conversion_gemini_preview.convertible_count', 1)
            ->assertSessionHas('conversion_gemini_preview.not_possible_count', 2);

        $this->actingAs($uploader)
            ->post(route('content.tasks.convert-gemini-apply', $task), ['json' => $json])
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertTrue((bool) ($chapter->extraction_items[0]['fill_blank_gemini_ready'] ?? false));
        $this->assertNotNull($chapter->extraction_items[0]['fill_blank_checked_at'] ?? null);
        $this->assertTrue((bool) ($chapter->extraction_items[1]['fill_blank_skipped'] ?? false));
        $this->assertTrue((bool) ($chapter->extraction_items[2]['fill_blank_skipped'] ?? false));

        $this->actingAs($uploader)
            ->post(route('content.tasks.submit-for-publish', $task))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_admin_textbook_index_sorts_by_book_then_chapter_and_links_gemini_convert(): void
    {
        $this->withoutVite();

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $bookA = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Alpha Book',
            'code' => 'AB',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $bookB = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Beta Book',
            'code' => 'BB',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $published = TextbookChapter::create([
            'textbook_id' => $bookB->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 3,
            'title' => 'Third',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [
                ['question_text' => '2 + 2?', 'correct_answer' => '4'],
            ],
        ]);
        TextbookChapter::create([
            'textbook_id' => $bookA->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'First',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        TextbookChapter::create([
            'textbook_id' => $bookB->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Second',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.textbooks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/Index')
                ->has('books', 2)
                ->where('chapters.0.book_name', 'Alpha Book')
                ->where('chapters.0.chapter_number', 1)
                ->where('chapters.1.book_name', 'Beta Book')
                ->where('chapters.1.chapter_number', 2)
                ->where('chapters.2.chapter_number', 3)
                ->where('chapters.2.can_convert_fill_blank', true),
            );

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.textbooks.index', ['book_id' => $bookB->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('chapters', 2)
                ->where('chapters.0.chapter_number', 2)
                ->where('chapters.1.chapter_number', 3),
            );

        $this->actingAs($admin)
            ->get(route('admin.textbooks.convert-gemini', $published))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/ConvertGemini')
                ->has('gemini.prompt'),
            );

        $json = json_encode([
            'questions' => [[
                'source_index' => 1,
                'question' => '2 + 2 = ____.',
                'answer_format' => 'integer',
                'correct_answer' => '4',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($admin)
            ->from(route('admin.textbooks.convert-gemini', $published))
            ->post(route('admin.textbooks.convert-gemini-apply', $published), ['json' => $json])
            ->assertRedirect(route('admin.textbooks.convert-gemini', $published))
            ->assertSessionHas('success');

        $published->refresh();
        $this->assertTrue((bool) ($published->extraction_items[0]['fill_blank_gemini_ready'] ?? false));
    }

    public function test_fill_blank_publish_copies_figure_from_published_mcq(): void
    {
        Storage::fake('public');

        [$grade, $syllabusChapter, $admin] = $this->seedGradeAndAdmin();

        $textbook = Textbook::create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $stagingPath = 'textbook-staging/diagrams/chart1.png';
        Storage::disk('public')->put($stagingPath, 'fake-diagram-bytes');

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 5,
            'title' => 'Data handling',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'question_text' => 'From the figure, how many students like cricket?',
                'correct_answer' => '12',
                'mcq_options' => [
                    ['text' => '10', 'is_correct' => false],
                    ['text' => '12', 'is_correct' => true],
                    ['text' => '14', 'is_correct' => false],
                    ['text' => '16', 'is_correct' => false],
                ],
                'needs_diagram' => true,
                'diagram_file' => 'chart1.png',
                'diagram_staging_path' => $stagingPath,
                'include_in_mcq' => true,
                'approved' => true,
            ]],
            'mcq_set_plan' => [[
                'set_code' => 'GP-C5-P1',
                'q_from' => 1,
                'q_to' => 1,
                'description' => 'Figure sum',
            ]],
        ]);

        $publish = app(TextbookChapterPublishService::class);
        $chapter = $publish->publish($chapter, $chapter->extraction_items, $admin, $chapter->mcq_set_plan);

        $mcq = Worksheet::query()->findOrFail($chapter->mcqWorksheetIds()[0])->questions()->firstOrFail();
        $this->assertNotNull($mcq->diagram_path);
        $this->assertTrue(Storage::disk('public')->exists($mcq->diagram_path));

        // Simulate Gemini conversion: fill-blank fields added, staging path cleared from JSON,
        // but the live MCQ question still has the figure.
        $items = $chapter->extraction_items;
        unset($items[0]['diagram_staging_path'], $items[0]['diagram_file'], $items[0]['needs_diagram']);
        $items[0]['fill_blank_question_text'] = 'How many students like cricket? ____';
        $items[0]['fill_blank_correct_answer'] = '12';
        $items[0]['fill_blank_answer_format'] = 'integer';
        $items[0]['include_in_fill_blank'] = true;
        $items[0]['include_in_written'] = true;
        $items[0]['fill_blank_checked_at'] = now()->toIso8601String();
        $chapter->update(['extraction_items' => $items]);

        $chapter = $publish->publishFillBlankAndWritten($chapter->fresh(), $admin);

        $fillBlank = Worksheet::query()
            ->findOrFail($chapter->fillBlankWorksheetIds()[0])
            ->questions()
            ->firstOrFail();

        $this->assertSame(Question::TYPE_FILL_IN_BLANK, $fillBlank->type);
        $this->assertNotNull($fillBlank->diagram_path, 'Fill-in-blank must keep the MCQ figure for student drill.');
        $this->assertTrue(Storage::disk('public')->exists($fillBlank->diagram_path));
        $this->assertNotSame($mcq->diagram_path, $fillBlank->diagram_path);
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
            'name' => 'Data handling',
            'chapter_number' => 'Ch 8',
            'sort_order' => 8,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $chapter, $admin];
    }
}
