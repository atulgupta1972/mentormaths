<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\WrittenSheetService;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrittenSheetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_written_practice_sheet_and_generate_pdf(): void
    {
        [$topic, $question, $admin] = $this->seedTopicQuestion();

        $service = app(WrittenSheetService::class);
        $worksheet = $service->createFromTopic($topic, [$question->id], $admin);
        $worksheet = $service->generatePdf($worksheet);

        $this->assertSame(WorksheetDeliveryMode::WRITTEN, $worksheet->delivery_mode);
        $this->assertSame(WrittenSheetStatus::PENDING_REVIEW, $worksheet->written_status);
        $this->assertNotNull($worksheet->written_pdf_path);
        $this->assertFileExists(storage_path('app/public/'.$worksheet->written_pdf_path));
    }

    public function test_verify_publishes_written_sheet(): void
    {
        [$topic, $question, $admin] = $this->seedTopicQuestion();

        $service = app(WrittenSheetService::class);
        $worksheet = $service->generatePdf(
            $service->createFromTopic($topic, [$question->id], $admin),
        );

        $verified = $service->verify($worksheet, $admin);

        $this->assertSame(WrittenSheetStatus::VERIFIED, $verified->written_status);
        $this->assertSame(Worksheet::STATUS_PUBLISHED, $verified->status);
        $this->assertNotNull($verified->written_verified_at);
    }

    public function test_create_written_sheet_from_manual_rows(): void
    {
        [$topic, , $admin] = $this->seedTopicQuestion();

        $service = app(WrittenSheetService::class);
        $worksheet = $service->generatePdf(
            $service->createFromManualQuestions(
                $topic->chapter,
                $topic,
                'practice',
                [[
                    'question_text' => 'Write 5 multiples of 3.',
                    'correct_answer' => '3,6,9,12,15',
                    'answer_format' => 'text',
                ]],
                $admin,
            ),
        );

        $this->assertSame(1, $worksheet->questions()->count());
        $this->assertDatabaseHas('questions', [
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Write 5 multiples of 3.',
            'source' => Question::SOURCE_MANUAL,
        ]);
    }

    public function test_create_written_sheet_from_manual_chapter_rows(): void
    {
        [$topic, , $admin] = $this->seedTopicQuestion();
        $chapter = $topic->chapter;
        $secondTopic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Division',
            'sort_order' => 2,
        ]);

        $service = app(WrittenSheetService::class);
        $worksheet = $service->generatePdf(
            $service->createFromManualQuestions(
                $chapter,
                null,
                'practice',
                [
                    [
                        'question_text' => 'What is 1/2 + 1/4?',
                        'topic_name' => $topic->name,
                        'correct_answer' => '3/4',
                        'answer_format' => 'fraction',
                    ],
                    [
                        'question_text' => 'What is 2/3 of 9?',
                        'topic_name' => $secondTopic->name,
                        'correct_answer' => '6',
                        'answer_format' => 'integer',
                    ],
                ],
                $admin,
            ),
        );

        $this->assertSame(2, $worksheet->questions()->count());
        $this->assertNotNull($worksheet->written_pdf_path);
    }

    /**
     * @return array{0: SyllabusTopic, 1: Question, 2: User}
     */
    private function seedTopicQuestion(): array
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

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$topic, $question, $admin];
    }
}
