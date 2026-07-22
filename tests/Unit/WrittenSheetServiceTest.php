<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
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
use App\Services\WrittenSheetService;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_can_replace_pdf_when_assigned_but_no_student_uploads(): void
    {
        Storage::fake('public');
        [$topic, $question, $admin] = $this->seedTopicQuestion();

        $service = app(WrittenSheetService::class);
        $worksheet = $service->verify(
            $service->generatePdf($service->createFromTopic($topic, [$question->id], $admin)),
            $admin,
        );

        $enrollment = $this->seedEnrollment($topic);
        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $this->assertTrue($service->canManagePdf($worksheet));

        $token = '550e8400-e29b-41d4-a716-446655440001';
        $oldPath = $worksheet->written_pdf_path;
        $newSource = "temp/pdf-imports/written-sheet-pdf/{$token}/source.pdf";
        Storage::disk('public')->put($oldPath, 'old pdf');
        Storage::disk('public')->put($newSource, 'new pdf');

        $replaced = $service->replacePdf($worksheet, $newSource, $token);

        $this->assertNotSame($oldPath, $replaced->written_pdf_path);
        $this->assertFalse(Storage::disk('public')->exists($oldPath));
        $this->assertTrue(Storage::disk('public')->exists($replaced->written_pdf_path));
        $this->assertSame(WrittenSheetStatus::VERIFIED, $replaced->written_status);
    }

    public function test_cannot_replace_pdf_after_student_upload(): void
    {
        [$topic, $question, $admin] = $this->seedTopicQuestion();

        $service = app(WrittenSheetService::class);
        $worksheet = $service->generatePdf($service->createFromTopic($topic, [$question->id], $admin));
        $enrollment = $this->seedEnrollment($topic);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_UPLOADED,
            'upload_paths' => ['written-submissions/test.jpg'],
            'uploaded_at' => now(),
        ]);

        $this->assertFalse($service->canManagePdf($worksheet));

        $this->expectException(\InvalidArgumentException::class);
        $service->replacePdf($worksheet, 'temp/pdf-imports/written-sheet-pdf/x/source.pdf');
    }

    private function seedEnrollment(SyllabusTopic $topic): StudentEnrollment
    {
        $student = Student::query()->create([
            'name' => 'Test Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);
        $syllabus = $topic->chapter->syllabusVersion;

        return StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $syllabus->academic_year_id,
            'grade_level_id' => $syllabus->grade_level_id,
            'board_id' => $syllabus->board_id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);
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
