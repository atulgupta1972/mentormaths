<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\StudentEnrollment;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\SetAttemptService;
use App\Support\AttemptResultSummary;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttemptResultSummaryFillBlankRedactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_review_hides_fill_blank_correct_answers(): void
    {
        [$attempt, $question] = $this->seedSubmittedFillBlankAttempt(wrong: true);

        $review = AttemptResultSummary::forStudentReview($attempt);
        $row = collect($review['questions'])->firstWhere('question_id', $question->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['needs_practice_retry']);
        $this->assertNull($row['correct_answer']);
        $this->assertSame('fill_in_blank', $row['type']);
        $this->assertNull(collect($review['wrong_questions'])->first()['correct_answer'] ?? null);
    }

    public function test_student_review_hides_mcq_correct_answers(): void
    {
        [$attempt, $question, $wrongOption] = $this->seedSubmittedMcqAttempt(wrong: true);

        $review = AttemptResultSummary::forStudentReview($attempt);
        $row = collect($review['questions'])->firstWhere('question_id', $question->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['needs_practice_retry']);
        $this->assertNull($row['correct_answer']);
        $this->assertSame(Question::TYPE_MCQ, $row['type']);
        $this->assertNull(collect($review['wrong_questions'])->first()['correct_answer'] ?? null);

        foreach ($row['options'] as $option) {
            $this->assertArrayNotHasKey('is_correct', $option);
        }
    }

    public function test_practice_retry_does_not_return_fill_blank_key_even_when_correct(): void
    {
        [$attempt, $question] = $this->seedSubmittedFillBlankAttempt(wrong: true);

        $result = app(SetAttemptService::class)->checkPracticeRetry(
            $attempt,
            $question->id,
            answerText: '-4',
        );

        $this->assertTrue($result['correct']);
        $this->assertNull($result['correct_answer']);
    }

    public function test_practice_retry_does_not_return_mcq_key_even_when_correct(): void
    {
        [$attempt, $question, $wrongOption, $correctOption] = $this->seedSubmittedMcqAttempt(wrong: true);

        $result = app(SetAttemptService::class)->checkPracticeRetry(
            $attempt,
            $question->id,
            optionId: $correctOption->id,
        );

        $this->assertTrue($result['correct']);
        $this->assertNull($result['correct_answer']);
    }

    /**
     * @return array{0: SetAttempt, 1: Question}
     */
    private function seedSubmittedFillBlankAttempt(bool $wrong): array
    {
        [$admin, $enrollment, $topic] = $this->seedBase();

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => '(-12) + 8 = ____',
            'source' => Question::SOURCE_MANUAL,
            'created_by' => $admin->id,
        ]);
        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'answer_format' => QuestionBlankAnswer::FORMAT_INTEGER,
            'correct_answer' => '-4',
        ]);

        $attempt = $this->seedSubmittedAttempt(
            admin: $admin,
            enrollment: $enrollment,
            topic: $topic,
            question: $question,
            title: 'Fill blank set',
            setCode: 'S711',
            wrong: $wrong,
            answerAttrs: [
                'answer_text' => $wrong ? '99' : '-4',
                'is_correct' => ! $wrong,
            ],
        );

        return [
            $attempt,
            $question->fresh('blankAnswer'),
        ];
    }

    /**
     * @return array{0: SetAttempt, 1: Question, 2: QuestionOption, 3: QuestionOption}
     */
    private function seedSubmittedMcqAttempt(bool $wrong): array
    {
        [$admin, $enrollment, $topic] = $this->seedBase();

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'question_text' => '2 + 2 = ?',
            'source' => Question::SOURCE_MANUAL,
            'created_by' => $admin->id,
        ]);
        $correctOption = QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '4',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        $wrongOption = QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '5',
            'is_correct' => false,
            'sort_order' => 2,
        ]);

        $attempt = $this->seedSubmittedAttempt(
            admin: $admin,
            enrollment: $enrollment,
            topic: $topic,
            question: $question,
            title: 'MCQ set',
            setCode: 'S712',
            wrong: $wrong,
            answerAttrs: [
                'question_option_id' => $wrong ? $wrongOption->id : $correctOption->id,
                'is_correct' => ! $wrong,
            ],
        );

        return [
            $attempt,
            $question->fresh('options'),
            $wrongOption,
            $correctOption,
        ];
    }

    /**
     * @return array{0: User, 1: StudentEnrollment, 2: SyllabusTopic}
     */
    private function seedBase(): array
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
        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'name' => 'Integers',
            'chapter_number' => 'Ch 1',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
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

        return [$admin, $enrollment, $topic];
    }

    /**
     * @param  array<string, mixed>  $answerAttrs
     */
    private function seedSubmittedAttempt(
        User $admin,
        StudentEnrollment $enrollment,
        SyllabusTopic $topic,
        Question $question,
        string $title,
        string $setCode,
        bool $wrong,
        array $answerAttrs,
    ): SetAttempt {
        $worksheet = Worksheet::query()->create([
            'title' => $title,
            'set_number' => 1,
            'set_code' => $setCode,
            'tier' => PracticeSetTier::CHAMPION,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);
        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now()->subHour(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now(),
            'completed_at' => now(),
            'status' => SetAttempt::STATUS_SUBMITTED,
            'score' => $wrong ? 0 : 1,
            'max_score' => 1,
        ]);

        SetAttemptAnswer::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            ...$answerAttrs,
        ]);

        return $attempt->fresh(['assignment.practiceSet.questions.options', 'assignment.practiceSet.questions.blankAnswer', 'answers']);
    }
}
