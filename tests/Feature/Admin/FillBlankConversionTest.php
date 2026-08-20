<?php

namespace Tests\Feature\Admin;

use App\Mail\ContentTaskAssignedUploader;
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
