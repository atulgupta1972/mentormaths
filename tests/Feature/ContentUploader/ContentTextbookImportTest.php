<?php

namespace Tests\Feature\ContentUploader;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentTextbookImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_uploader_import_stays_on_content_chapter_page(): void
    {
        $this->withoutVite();

        [$uploader, $chapter] = $this->seedUploaderWithChapter();

        $json = json_encode([
            'questions' => [
                [
                    'topic' => 'Addition',
                    'question' => 'What is 2 + 2?',
                    'options' => ['3', '4', '5', '6'],
                    'correct_index' => 1,
                    'hint' => 'Add',
                    'explanation' => '2+2=4',
                    'difficulty' => 'Easy',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.import-mcq', $chapter), ['json' => $json])
            ->assertRedirect(route('content.textbooks.show', $chapter))
            ->assertSessionHas('success');

        $chapter->refresh();
        $this->assertSame(TextbookChapter::STATUS_REVIEW, $chapter->status);
        $this->assertCount(1, $chapter->extraction_items ?? []);

        $this->actingAs($uploader)
            ->get(route('content.textbooks.show', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/Show')
                ->where('uploaderMode', true)
                ->where('routeNamespace', 'content')
                ->has('chapter.items', 1));
    }

    public function test_content_uploader_can_mark_upload_complete_and_open_verification(): void
    {
        $this->withoutVite();

        [$uploader, $chapter, $task] = $this->seedUploaderWithChapter(withTask: true);

        $json = json_encode([
            'questions' => [
                [
                    'topic' => 'Addition',
                    'question' => 'What is 2 + 2?',
                    'options' => ['3', '4', '5', '6'],
                    'correct_index' => 1,
                    'hint' => 'Add',
                    'explanation' => '2+2=4',
                    'difficulty' => 'Easy',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($uploader)
            ->post(route('content.textbooks.import-mcq', $chapter), ['json' => $json])
            ->assertRedirect();

        $chapter->refresh();

        $this->actingAs($uploader)
            ->post(route('content.textbooks.publish', $chapter), [
                'items' => $chapter->extraction_items,
            ])
            ->assertRedirect();

        $this->actingAs($uploader)
            ->from(route('content.tasks.show', $task))
            ->post(route('content.tasks.mark-uploaded', $task))
            ->assertRedirect(route('content.tasks.show', $task))
            ->assertSessionHas('success');

        $this->actingAs($uploader)
            ->get(route('content.tasks.show', $task))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ContentUploader/Tasks/Show')
                ->where('task.status', ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS)
                ->has('verification.questions', 1)
                ->where('verification.questions.0.question_text', 'What is 2 + 2?')
                ->has('verification.questions.0.options', 4)
                ->where('verification.questions.0.options.1.is_correct', true));

        $questionId = ContentUploadTask::query()->findOrFail($task->id)
            ->textbookChapter
            ->mcqWorksheetIds();

        $worksheetId = $questionId[0];
        $savedQuestionId = \App\Models\Worksheet::query()->findOrFail($worksheetId)->questions()->firstOrFail()->id;

        $this->actingAs($uploader)
            ->post(route('content.tasks.verification-question', $task), [
                'run_id' => \App\Models\ContentVerificationRun::query()->where('content_upload_task_id', $task->id)->value('id'),
                'question_id' => $savedQuestionId,
                'question_text' => 'What is two plus two?',
                'explanation' => 'Adding gives four.',
                'method_hint' => 'Use addition',
                'difficulty' => 'Easy',
                'options' => [
                    ['id' => null, 'option_text' => '3', 'is_correct' => false],
                    ['id' => null, 'option_text' => '4', 'is_correct' => true],
                    ['id' => null, 'option_text' => '5', 'is_correct' => false],
                    ['id' => null, 'option_text' => '6', 'is_correct' => false],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('questions', [
            'id' => $savedQuestionId,
            'question_text' => 'What is two plus two?',
            'explanation' => 'Adding gives four.',
        ]);
    }

    public function test_content_uploader_without_student_profile_is_sent_to_tasks_from_dashboard(): void
    {
        $uploader = User::factory()->create(['role' => User::ROLE_TEACHER]);
        app(UserGroupService::class)->attachGroupByCode($uploader, User::ROLE_CONTENT_UPLOADER);

        $this->actingAs($uploader)
            ->get(route('dashboard'))
            ->assertRedirect(route('content.tasks.index'));
    }

    /**
     * @return array{0: User, 1: TextbookChapter, 2?: ContentUploadTask}
     */
    private function seedUploaderWithChapter(bool $withTask = false): array
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

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'offered_amount_inr' => 5000,
            'agreed_amount_inr' => 5000,
            'agreed_at' => now(),
        ]);

        if ($withTask) {
            return [$uploader, $chapter, $task];
        }

        return [$uploader, $chapter];
    }
}
