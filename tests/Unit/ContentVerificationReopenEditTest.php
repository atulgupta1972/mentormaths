<?php

namespace Tests\Unit;

use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\ContentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentVerificationReopenEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploader_can_edit_after_locking_verification(): void
    {
        [$uploader, $task, $run, $question] = $this->seedVerifiedRun();

        $service = app(ContentVerificationService::class);

        $service->saveQuestion($run->fresh(), (int) $question->id, [
            'question_text' => 'Updated complementary angles question',
            'explanation' => '90 - 53 = 37',
            'method_hint' => 'Complementary angles add to 90°.',
            'difficulty' => 'Easy',
            'options' => [
                ['option_text' => '53°', 'is_correct' => false],
                ['option_text' => '37°', 'is_correct' => true],
                ['option_text' => '127°', 'is_correct' => false],
                ['option_text' => '147°', 'is_correct' => false],
            ],
        ], $uploader);

        $this->assertSame(
            'Updated complementary angles question',
            $question->fresh()->question_text,
        );
        $this->assertSame(
            'Complementary angles add to 90°.',
            $question->fresh()->method_hint,
        );
        // Save may auto-complete again once every check is still verified.
        $this->assertContains($task->fresh()->status, [
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED,
        ]);
    }

    /**
     * @return array{0: User, 1: ContentUploadTask, 2: ContentVerificationRun, 3: Question}
     */
    private function seedVerifiedRun(): array
    {
        $uploader = User::factory()->create(['role' => User::ROLE_CONTENT_UPLOADER]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $year = \App\Models\AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = \App\Models\Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = \App\Models\GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = \App\Models\Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $syllabus = \App\Models\SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $syllabusChapter = \App\Models\SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Angles',
            'sort_order' => 1,
        ]);
        $topic = \App\Models\SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $syllabusChapter->id,
            'name' => 'Complementary',
            'sort_order' => 1,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'MCQ set',
            'set_number' => 1,
            'set_code' => 'S151',
            'status' => Worksheet::STATUS_PUBLISHED,
            'syllabus_topic_id' => $topic->id,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Complement of 53°?',
            'type' => Question::TYPE_MCQ,
            'difficulty' => 'Easy',
            'explanation' => 'Old',
            'method_hint' => 'Old hint',
        ]);
        $worksheet->questions()->attach($question->id, ['sort_order' => 0]);
        foreach (['53°', '37°', '127°', '147°'] as $i => $text) {
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'option_text' => $text,
                'is_correct' => $i === 1,
                'sort_order' => $i,
            ]);
        }

        $textbook = Textbook::query()->create([
            'name' => 'Book',
            'code' => 'BK',
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'created_by' => $admin->id,
        ]);
        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Angles',
            'mcq_worksheet_id' => $worksheet->id,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_VERIFIED,
            'offered_amount_inr' => 1000,
            'agreed_amount_inr' => 1000,
            'agreed_at' => now(),
        ]);

        $run = ContentVerificationRun::query()->create([
            'content_upload_task_id' => $task->id,
            'user_id' => $uploader->id,
            'status' => ContentVerificationRun::STATUS_COMPLETED,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        ContentVerificationCheck::query()->create([
            'content_verification_run_id' => $run->id,
            'question_id' => $question->id,
            'verified' => true,
            'verified_at' => now(),
        ]);

        return [$uploader, $task, $run, $question];
    }
}
