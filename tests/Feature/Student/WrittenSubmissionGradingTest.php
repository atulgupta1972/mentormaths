<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
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
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WrittenSubmissionGradingTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_stays_pending_for_manual_teacher_marks(): void
    {
        Storage::fake('public');

        [$assignment] = $this->seedWrittenAssignment();

        $service = app(WrittenSubmissionService::class);
        $file = UploadedFile::fake()->image('answer.jpg');

        $submission = $service->store($assignment, [$file]);
        $this->assertSame(WrittenSubmission::STATUS_UPLOADED, $submission->status);

        app()->terminate();

        $submission->refresh();
        $this->assertSame(WrittenSubmission::STATUS_UPLOADED, $submission->status);
        $this->assertNull($submission->score);
    }

    public function test_teacher_can_apply_manual_grade_and_feedback(): void
    {
        [$assignment] = $this->seedWrittenAssignment();

        $submission = app(WrittenSubmissionService::class)->applyManualGrade($assignment, [
            'score' => 7,
            'max_score' => 10,
            'feedback' => 'Revise fractions.',
        ]);

        $this->assertSame(WrittenSubmission::STATUS_GRADED, $submission->status);
        $this->assertSame(7, $submission->score);
        $this->assertSame(10, $submission->max_score);
        $this->assertSame('Revise fractions.', $submission->ai_summary);
        $this->assertSame(SetAssignment::STATUS_COMPLETED, $assignment->fresh()->status);
    }

    public function test_weekly_summary_includes_manual_written_grade(): void
    {
        [$assignment] = $this->seedWrittenAssignment();
        $enrollment = $assignment->enrollment;

        app(WrittenSubmissionService::class)->applyManualGrade($assignment, [
            'score' => 8,
            'max_score' => 10,
            'feedback' => 'Neat work.',
        ]);

        $summary = app(\App\Services\StudentProgressSummaryService::class)->build($enrollment, now());

        $this->assertSame(1, $summary['stats']['completed_count']);
        $this->assertSame('C7-INT-ADD-P1-W', $summary['completed'][0]['set_code']);
        $this->assertSame(8, $summary['completed'][0]['latest_score']);
        $this->assertSame(10, $summary['completed'][0]['latest_max_score']);
        $this->assertStringContainsString('Neat work.', $summary['completed'][0]['review_items'][0]['label']);
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
