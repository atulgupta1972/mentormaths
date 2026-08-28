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
use App\Services\ContentAiVerificationService;
use App\Services\ContentVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentVerificationGeminiRecheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixing_gemini_flagged_question_on_published_task_queues_single_recheck(): void
    {
        [$uploader, $task, $run, $approved, $flagged] = $this->seedPublishedGeminiRun();

        $service = app(ContentVerificationService::class);

        $service->saveQuestion($run->fresh(), (int) $flagged->id, [
            'question_text' => 'Corrected question text',
            'explanation' => 'Fixed explanation',
            'method_hint' => 'Fixed hint',
            'difficulty' => 'Easy',
            'options' => [
                ['option_text' => 'Wrong', 'is_correct' => false],
                ['option_text' => 'Right', 'is_correct' => true],
            ],
        ], $uploader);

        $payload = $service->forTask($task->fresh(), $uploader);
        $progress = $service->progressForTask($task->fresh(), $uploader);

        $this->assertSame(1, $service->countPendingGeminiQuestions($payload['questions']));
        $this->assertSame(1, $progress['pending']);
        $this->assertSame(1, $progress['verified']);

        $flaggedRow = collect($payload['questions'])->firstWhere('question_id', $flagged->id);
        $this->assertNull($flaggedRow['ai_verdict']);
        $this->assertFalse($flaggedRow['is_verified']);

        $approvedRow = collect($payload['questions'])->firstWhere('question_id', $approved->id);
        $this->assertSame(ContentAiVerificationService::VERDICT_APPROVE, $approvedRow['ai_verdict']);
    }

    /**
     * @return array{0: User, 1: ContentUploadTask, 2: ContentVerificationRun, 3: Question, 4: Question}
     */
    private function seedPublishedGeminiRun(): array
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
            'name' => 'Algebra',
            'sort_order' => 1,
        ]);
        $topic = \App\Models\SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $syllabusChapter->id,
            'name' => 'Terms',
            'sort_order' => 1,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'MCQ set',
            'set_number' => 1,
            'set_code' => 'S151',
            'status' => Worksheet::STATUS_PUBLISHED,
            'syllabus_topic_id' => $topic->id,
        ]);

        $approved = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Approved question',
            'type' => Question::TYPE_MCQ,
            'difficulty' => 'Easy',
        ]);
        $flagged = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Flagged question',
            'type' => Question::TYPE_MCQ,
            'difficulty' => 'Easy',
        ]);

        foreach ([$approved, $flagged] as $index => $question) {
            $worksheet->questions()->attach($question->id, ['sort_order' => $index]);
            foreach (['A', 'B'] as $optionIndex => $label) {
                QuestionOption::query()->create([
                    'question_id' => $question->id,
                    'option_text' => $label,
                    'is_correct' => $optionIndex === 1,
                    'sort_order' => $optionIndex,
                ]);
            }
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
            'title' => 'Algebra',
            'mcq_worksheet_id' => $worksheet->id,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
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
            'question_id' => $approved->id,
            'check_text' => true,
            'check_options' => true,
            'check_correct' => true,
            'check_hint' => true,
            'check_explanation' => true,
            'check_difficulty' => true,
            'check_diagram' => true,
            'verified_at' => now(),
            'ai_verdict' => ContentAiVerificationService::VERDICT_APPROVE,
            'ai_reviewed_at' => now(),
        ]);

        ContentVerificationCheck::query()->create([
            'content_verification_run_id' => $run->id,
            'question_id' => $flagged->id,
            'ai_verdict' => ContentAiVerificationService::VERDICT_NEEDS_FIX,
            'ai_note' => 'Wrong answer marked correct',
            'ai_reviewed_at' => now(),
        ]);

        return [$uploader, $task, $run, $approved, $flagged];
    }
}
