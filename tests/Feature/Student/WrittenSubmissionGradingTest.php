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
use App\Services\WrittenSubmissionService;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        [$assignment, $studentUser] = $this->seedWrittenAssignment();

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
