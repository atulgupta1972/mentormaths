<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\GuidedAttemptQuestion;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\GuidedPracticeService;
use App\Services\SetAttemptService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuidedPracticeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_twice_shows_explanation_phase(): void
    {
        [$attempt, $wrongOption, $correctOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);

        $service->submitAnswer($attempt, $wrongOption->id);
        $attempt->refresh();
        $payload = $service->submitAnswer($attempt, $wrongOption->id);

        $this->assertSame('explained', $payload['phase']);
        $this->assertTrue($payload['show_explanation']);
        $this->assertSame('Add the two whole numbers together.', $payload['question']['method_hint']);
        $this->assertStringNotContainsString('Answer key', $payload['question']['method_hint'] ?? '');

        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $correctOption->id);
        $attempt->refresh();

        $guided = $attempt->guidedQuestions->first();
        $this->assertTrue($guided->corrected_after_help);
        $this->assertSame(GuidedAttemptQuestion::PHASE_DONE, $guided->phase);
    }

    public function test_give_up_queues_resolution_item(): void
    {
        [$attempt, $wrongOption] = $this->seedGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $service->submitAnswer($attempt, $wrongOption->id);
        $service->submitAnswer($attempt->fresh(['guidedQuestions.question.options']), $wrongOption->id);

        $service->giveUp($attempt->fresh(['guidedQuestions', 'assignment']));

        $this->assertDatabaseHas('question_resolution_items', [
            'status' => 'pending',
        ]);
    }

    public function test_fill_in_blank_wrong_twice_shows_explanation_phase(): void
    {
        [$attempt] = $this->seedFillBlankGuidedAttempt();

        $service = app(GuidedPracticeService::class);

        $service->submitAnswer($attempt, null, '-3');
        $payload = $service->submitAnswer($attempt->fresh(['guidedQuestions.question.blankAnswer']), null, '-3');

        $this->assertSame('explained', $payload['phase']);
        $this->assertTrue($payload['show_explanation']);
        $this->assertSame('fill_in_blank', $payload['question']['type']);
    }

    public function test_fill_in_blank_correct_on_first_try(): void
    {
        [$attempt] = $this->seedFillBlankGuidedAttempt();

        $service = app(GuidedPracticeService::class);
        $payload = $service->submitAnswer($attempt, null, '-4');

        $this->assertSame('correct', $payload['feedback']['type']);
        $this->assertTrue($attempt->fresh()->guidedQuestions->first()->first_try_correct);
    }

    public function test_stale_batch_attempt_on_topic_practice_upgrades_to_guided(): void
    {
        [$attempt] = $this->seedGuidedAttempt(withGuidedInit: false);

        app(SetAttemptService::class)->ensureGuidedForTopicPractice($attempt);

        $attempt->refresh();
        $this->assertTrue($attempt->isGuided());
        $this->assertSame(1, $attempt->guidedQuestions()->count());
    }

    /**
     * @return array{0: SetAttempt, 1?: QuestionOption, 2?: QuestionOption}
     */
    private function seedGuidedAttempt(bool $withGuidedInit = true): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create([
            'code' => 'CBSE',
            'name' => 'CBSE',
            'is_active' => true,
        ]);

        $grade = GradeLevel::query()->create([
            'name' => 'Class 7',
            'sort_order' => 7,
            'is_active' => true,
        ]);

        $subject = Subject::query()->create([
            'code' => 'MATHS',
            'name' => 'Mathematics',
        ]);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Test Student',
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

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter set',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'What is 2 + 2?',
            'explanation' => 'Add the two numbers. Answer key: b.',
            'method_hint' => 'Add the two whole numbers together.',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_MANUAL,
        ]);

        $wrongOption = QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '3',
            'is_correct' => false,
            'sort_order' => 1,
        ]);

        $correctOption = QuestionOption::query()->create([
            'question_id' => $question->id,
            'option_text' => '4',
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_IN_PROGRESS,
        ]);

        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => $withGuidedInit ? SetAttempt::MODE_GUIDED : SetAttempt::MODE_BATCH,
            'started_at' => now(),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        if ($withGuidedInit) {
            GuidedAttemptQuestion::query()->create([
                'set_attempt_id' => $attempt->id,
                'question_id' => $question->id,
                'sort_order' => 0,
                'phase' => GuidedAttemptQuestion::PHASE_ANSWERING,
            ]);
        }

        return [$attempt, $wrongOption, $correctOption];
    }

    /**
     * @return array{0: SetAttempt}
     */
    private function seedFillBlankGuidedAttempt(): array
    {
        [$attempt, , ] = $this->seedGuidedAttempt(withGuidedInit: false);

        $attempt->update(['mode' => SetAttempt::MODE_GUIDED]);

        $assignment = $attempt->assignment()->with('practiceSet.questions')->first();
        $question = $assignment->practiceSet->questions->first();

        $question->update([
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => '(-12) + 8 = ____',
            'method_hint' => 'Subtract absolute values and keep the sign of the larger number.',
        ]);

        $question->options()->delete();

        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'answer_format' => QuestionBlankAnswer::FORMAT_INTEGER,
            'correct_answer' => '-4',
        ]);

        GuidedAttemptQuestion::query()->create([
            'set_attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'sort_order' => 0,
            'phase' => GuidedAttemptQuestion::PHASE_ANSWERING,
        ]);

        return [$attempt->fresh(['guidedQuestions.question.blankAnswer'])];
    }
}
