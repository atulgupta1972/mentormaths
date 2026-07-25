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
