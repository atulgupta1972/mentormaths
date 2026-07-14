<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\QuestionSetAudit;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\QuestionAuditService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_flags_mcq_with_no_correct_option(): void
    {
        [$worksheet, $question] = $this->seedWorksheetWithMcq([
            ['text' => '5', 'correct' => false],
            ['text' => '6', 'correct' => false],
        ]);

        $result = app(QuestionAuditService::class)->auditWorksheet($worksheet);

        $this->assertSame(QuestionSetAudit::STATUS_ISSUES, $result['status']);
        $this->assertTrue(collect($result['findings'])->contains('issue_type', 'no_correct_option'));
        $this->assertSame($question->id, $result['findings'][0]['question_id']);
    }

    public function test_audit_flags_linear_equation_mismatch(): void
    {
        [$worksheet] = $this->seedWorksheetWithFillBlank('Solve for x: 3x - 7 = 11', '16', QuestionBlankAnswer::FORMAT_INTEGER);

        $result = app(QuestionAuditService::class)->auditWorksheet($worksheet);

        $this->assertSame(QuestionSetAudit::STATUS_ISSUES, $result['status']);
        $this->assertTrue(collect($result['findings'])->contains('issue_type', 'answer_mismatch'));
    }

    public function test_audit_passes_clean_fill_blank(): void
    {
        [$worksheet] = $this->seedWorksheetWithFillBlank('Solve for x: 3x - 7 = 11', '6', QuestionBlankAnswer::FORMAT_INTEGER);

        $result = app(QuestionAuditService::class)->auditWorksheet($worksheet);

        $this->assertSame(QuestionSetAudit::STATUS_CLEAN, $result['status']);
        $this->assertSame(0, $result['issue_count']);
    }

    public function test_record_audit_persists_findings(): void
    {
        [$worksheet] = $this->seedWorksheetWithFillBlank('Solve for x: x + 2 = 5', '99', QuestionBlankAnswer::FORMAT_INTEGER);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $service = app(QuestionAuditService::class);
        $result = $service->auditWorksheet($worksheet);
        $audit = $service->recordAudit($worksheet, $admin, $result);

        $this->assertDatabaseHas('question_set_audits', [
            'worksheet_id' => $worksheet->id,
            'audited_by' => $admin->id,
            'status' => QuestionSetAudit::STATUS_ISSUES,
        ]);
        $this->assertGreaterThan(0, $audit->issue_count);
        $this->assertNotEmpty($audit->findings);
    }

    /**
     * @param  list<array{text: string, correct: bool}>  $options
     * @return array{0: Worksheet, 1: Question}
     */
    private function seedWorksheetWithMcq(array $options): array
    {
        [$topic] = $this->seedTopic();

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'question_text' => 'What is 2 + 3?',
            'source' => Question::SOURCE_MANUAL,
        ]);

        foreach ($options as $index => $option) {
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'option_text' => $option['text'],
                'is_correct' => $option['correct'],
                'sort_order' => $index + 1,
            ]);
        }

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        return [$worksheet->fresh(['questions.options']), $question];
    }

    /**
     * @return array{0: Worksheet}
     */
    private function seedWorksheetWithFillBlank(string $questionText, string $answer, string $format): array
    {
        [$topic] = $this->seedTopic();

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => $questionText,
            'source' => Question::SOURCE_MANUAL,
        ]);

        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'answer_format' => $format,
            'correct_answer' => $answer,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Starter',
            'set_number' => 1,
            'set_code' => 'SF711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        return [$worksheet->fresh(['questions.blankAnswer'])];
    }

    /**
     * @return array{0: SyllabusTopic}
     */
    private function seedTopic(): array
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
            'name' => 'Linear Equations',
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Basics',
            'sort_order' => 1,
        ]);

        return [$topic];
    }
}
