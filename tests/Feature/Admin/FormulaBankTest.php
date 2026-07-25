<?php

namespace Tests\Feature\Admin;

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
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormulaBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_formula_set_and_import_mcqs(): void
    {
        [$admin, $topic] = $this->seedTopic();

        $create = $this->actingAs($admin)->post(route('admin.formula-bank.topics.sets.store', $topic), [
            'title' => 'Algebra identities set 1',
        ]);
        $create->assertRedirect();

        $set = Worksheet::query()->where('purpose', WorksheetPurpose::FORMULA)->first();
        $this->assertNotNull($set);
        $this->assertSame(1, $set->set_number);
        $this->assertTrue($set->isFormula());

        $json = json_encode([
            'questions' => [
                [
                    'question' => 'Which is (a+b)²?',
                    'options' => ['a²+b²', 'a²+2ab+b²', 'a²-b²', '2ab'],
                    'correct_index' => 1,
                    'explanation' => 'Expand binomial.',
                ],
            ],
        ]);

        $import = $this->actingAs($admin)->post(route('admin.formula-bank.sets.import', $set), [
            'json' => $json,
        ]);
        $import->assertRedirect();

        $this->assertSame(1, $set->fresh()->questions()->count());
        $question = Question::query()->first();
        $this->assertSame(QuestionBankPurpose::FORMULA, $question->bank_purpose);
        $this->assertSame(Question::TYPE_MCQ, $question->type);
    }

    public function test_formula_matrix_page_loads(): void
    {
        $this->withoutVite();
        [$admin] = $this->seedTopic();
        $board = Board::query()->first();

        $this->actingAs($admin)
            ->get(route('admin.formula-bank.index', ['board_id' => $board->id]))
            ->assertOk();
    }

    public function test_topic_prompt_can_include_focus_description(): void
    {
        [$admin, $topic] = $this->seedTopic();

        $response = $this->actingAs($admin)->post(route('admin.formula-bank.topics.prompt', $topic), [
            'total' => 6,
            'style' => 'formula_recall',
            'focus' => '(a+b)^2 and a^2-b^2 identities as MCQs',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('formula_bank_topic_prompt');
        $prompt = session('formula_bank_topic_prompt');
        $this->assertStringContainsString('FORMULA / CONCEPT', $prompt);
        $this->assertStringContainsString('(a+b)^2 and a^2-b^2', $prompt);
        $this->assertStringContainsString('Exactly 6 MCQ cards', $prompt);
    }

    public function test_chapter_import_pastes_json_into_topics_and_sets(): void
    {
        [$admin, $topic] = $this->seedTopic();
        $chapter = $topic->chapter;

        $json = json_encode([
            'questions' => [
                [
                    'topic' => $topic->name,
                    'question' => 'Which is true for integers?',
                    'options' => ['0 is positive', '0 is neither', '0 is negative', '0 is even only'],
                    'correct_index' => 1,
                    'explanation' => '0 is neither positive nor negative.',
                ],
            ],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.formula-bank.chapters.import', $chapter), [
            'json' => $json,
            'create_sets' => true,
        ]);
        $response->assertRedirect();

        $this->assertSame(1, Question::query()->where('bank_purpose', QuestionBankPurpose::FORMULA)->count());
        $this->assertSame(1, Worksheet::query()->where('purpose', WorksheetPurpose::FORMULA)->count());
        $set = Worksheet::query()->where('purpose', WorksheetPurpose::FORMULA)->first();
        $this->assertSame(1, $set->questions()->count());
    }

    public function test_add_formulas_chapter_page_loads_from_question_hub(): void
    {
        $this->withoutVite();
        [$admin, $topic] = $this->seedTopic();
        $chapter = $topic->chapter;

        $this->actingAs($admin)
            ->get(route('admin.formula-bank.chapters.show', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/FormulaBank/ChapterShow')
                ->has('topics', 1));
    }

    /**
     * @return array{0: User, 1: SyllabusTopic}
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
            'name' => 'Algebraic Expressions',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Identities',
            'sort_order' => 1,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$admin, $topic];
    }
}
