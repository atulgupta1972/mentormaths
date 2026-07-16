<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\GuidedAttemptQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\CatchUpSetService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatchUpSetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_import_creates_per_student_catch_up_set(): void
    {
        [$topic, $enrollment, $sourceQuestion, $assigner] = $this->seedWeakGuidedContext();

        $service = app(CatchUpSetService::class);
        $weak = $service->weakStudentsForTopic($topic);

        $this->assertCount(1, $weak);
        $this->assertSame($enrollment->id, $weak[0]['student_enrollment_id']);
        $this->assertSame(1, $weak[0]['weak_count']);

        $prompt = $service->buildBatchPrompt($topic, [$enrollment->id]);
        $this->assertStringContainsString((string) $sourceQuestion->id, $prompt);
        $this->assertStringContainsString('student_enrollment_id='.$enrollment->id, $prompt);

        $json = json_encode([
            'students' => [[
                'student_enrollment_id' => $enrollment->id,
                'variants' => [[
                    'source_question_id' => $sourceQuestion->id,
                    'type' => 'mcq',
                    'question' => 'What is 3 + 5?',
                    'options' => ['6', '7', '8', '9'],
                    'correct_index' => 2,
                    'method_hint' => 'Add the whole numbers.',
                    'explanation' => '3 + 5 = 8',
                    'difficulty' => 'Easy',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $result = $service->importAndCreate(
            $topic,
            $json,
            [$enrollment->id],
            $assigner,
            now()->addWeek()->toDateString(),
        );

        $this->assertCount(1, $result['created']);
        $this->assertSame('S711-PC1', $result['created'][0]['set_code']);

        $catchUp = Worksheet::query()->where('purpose', WorksheetPurpose::CATCH_UP)->first();
        $this->assertNotNull($catchUp);
        $this->assertSame($enrollment->id, $catchUp->catch_up_for_enrollment_id);
        $this->assertSame([$sourceQuestion->id], $catchUp->catch_up_source_question_ids);
        $this->assertSame(1, $catchUp->questions()->count());

        $this->assertDatabaseHas('set_assignments', [
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $catchUp->id,
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $weakAfter = $service->weakStudentsForTopic($topic);
        $this->assertSame([], $weakAfter);
    }

    /**
     * @return array{0: SyllabusTopic, 1: StudentEnrollment, 2: Question, 3: User}
     */
    private function seedWeakGuidedContext(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE']);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7]);
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

        $student = Student::query()->create([
            'name' => 'Riya',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $assigner = User::factory()->create();

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter set',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'created_by' => $assigner->id,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is 2 + 2?',
            'explanation' => '2 + 2 = 4',
            'method_hint' => 'Add the two whole numbers.',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '3',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '4',
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $assigner->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_GUIDED,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'score' => 0,
            'max_score' => 1,
        ]);

        GuidedAttemptQuestion::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'sort_order' => 0,
            'phase' => GuidedAttemptQuestion::PHASE_DONE,
            'wrong_before_explanation' => 1,
            'first_try_correct' => false,
            'corrected_after_help' => true,
            'used_early_hint' => true,
            'final_is_correct' => true,
        ]);

        return [$topic, $enrollment, $question, $assigner];
    }
}
