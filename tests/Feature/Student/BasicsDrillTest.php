<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\BasicsDrillItem;
use App\Models\BasicsDrillSession;
use App\Models\BasicsDrillSetting;
use App\Models\Board;
use App\Models\FormulaDrillSession;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BasicsDrillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array{student: Student, user: User, grade: GradeLevel}
     */
    private function seedStudentWithFormulaComplete(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Basics Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);

        StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        FormulaDrillSession::query()->create([
            'student_id' => $student->id,
            'drill_date' => now(config('formula_drill.timezone', 'Asia/Kolkata'))->startOfDay(),
            'status' => FormulaDrillSession::STATUS_COMPLETED,
            'questions_total' => 1,
            'questions_completed' => 1,
            'pool_size' => 1,
            'completed_at' => now(),
        ]);

        BasicsDrillSetting::query()->create([
            'grade_level_id' => $grade->id,
            'tables_enabled' => true,
            'squares_enabled' => false,
            'cubes_enabled' => false,
            'table_from' => 2,
            'table_to' => 2,
            'multiplier_from' => 2,
            'multiplier_to' => 3,
            'square_from' => 2,
            'square_to' => 30,
            'cube_from' => 2,
            'cube_to' => 13,
            'squares_per_day' => 5,
            'cubes_per_day' => 3,
            'seconds_per_blank' => 5,
        ]);

        return compact('student', 'user', 'grade');
    }

    public function test_dashboard_redirects_to_basics_drill_after_formula_complete(): void
    {
        ['user' => $user] = $this->seedStudentWithFormulaComplete();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('student.basics-drill.show'));
    }

    public function test_student_can_start_and_complete_table_drill(): void
    {
        ['student' => $student, 'user' => $user] = $this->seedStudentWithFormulaComplete();

        $this->actingAs($user)
            ->get(route('student.basics-drill.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Student/BasicsDrill/Show'));

        $session = BasicsDrillSession::query()->where('student_id', $student->id)->firstOrFail();
        $this->assertSame(BasicsDrillSession::PHASE_TABLE_SHOW, $session->phase);

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.start', $session))
            ->assertOk()
            ->assertJsonPath('session.phase', BasicsDrillSession::PHASE_TABLE_DRILL);

        $session->refresh();
        $this->assertCount(2, $session->items);

        foreach ($session->fresh('items')->items as $item) {
            $this->actingAs($user)
                ->postJson(route('student.basics-drill.answer', $item), [
                    'answer' => (string) $item->correct_answer,
                ])
                ->assertOk();
        }

        $session->refresh();
        $this->assertTrue($session->isComplete());

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_wrong_answer_requires_acknowledgement(): void
    {
        ['student' => $student, 'user' => $user] = $this->seedStudentWithFormulaComplete();

        $this->actingAs($user)
            ->get(route('student.basics-drill.show'))
            ->assertOk();

        $session = BasicsDrillSession::query()->where('student_id', $student->id)->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.start', $session))
            ->assertOk();

        $item = $session->fresh('items')->items->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.answer', $item), [
                'answer' => '0',
            ])
            ->assertOk()
            ->assertJsonPath('reveal', true)
            ->assertJsonPath('correct_answer', (int) $item->correct_answer);

        $this->assertDatabaseHas('basics_drill_items', [
            'id' => $item->id,
            'status' => BasicsDrillItem::STATUS_FAILED,
        ]);

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.acknowledge', $item))
            ->assertOk();
    }

    public function test_stuck_cube_drill_with_no_pending_items_recovers_on_reload(): void
    {
        ['student' => $student, 'user' => $user, 'grade' => $grade] = $this->seedStudentWithFormulaComplete();

        BasicsDrillSetting::query()->where('grade_level_id', $grade->id)->update([
            'tables_enabled' => false,
            'squares_enabled' => false,
            'cubes_enabled' => true,
            'cube_from' => 2,
            'cube_to' => 4,
            'cubes_per_day' => 3,
        ]);

        $session = BasicsDrillSession::query()->create([
            'student_id' => $student->id,
            'student_enrollment_id' => $student->currentEnrollment()?->id,
            'drill_date' => now(config('basics_drill.timezone', 'Asia/Kolkata'))->startOfDay(),
            'status' => BasicsDrillSession::STATUS_IN_PROGRESS,
            'phase' => BasicsDrillSession::PHASE_CUBES_DRILL,
            'cube_batch_start' => 2,
        ]);

        foreach ([2, 3, 4] as $index => $n) {
            BasicsDrillItem::query()->create([
                'basics_drill_session_id' => $session->id,
                'fact_type' => BasicsDrillItem::TYPE_CUBE,
                'fact_key' => "cb{$n}",
                'operand_a' => $n,
                'operand_b' => 0,
                'correct_answer' => $n * $n * $n,
                'sort_order' => $index + 1,
                'round' => BasicsDrillItem::ROUND_MAIN,
                'status' => BasicsDrillItem::STATUS_CORRECT,
            ]);
        }

        $this->actingAs($user)
            ->get(route('student.basics-drill.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('session.is_complete', true)
                ->where('session.phase', BasicsDrillSession::PHASE_COMPLETED));

        $this->assertTrue($session->fresh()->isComplete());
    }

    public function test_wrong_table_answer_is_retried_in_final_correction_before_dashboard(): void
    {
        ['student' => $student, 'user' => $user] = $this->seedStudentWithFormulaComplete();

        $this->actingAs($user)->get(route('student.basics-drill.show'))->assertOk();

        $session = BasicsDrillSession::query()->where('student_id', $student->id)->firstOrFail();

        $this->actingAs($user)->postJson(route('student.basics-drill.start', $session))->assertOk();

        $items = $session->fresh('items')->items->values();
        $wrongItem = $items->firstOrFail();
        $rightItem = $items->last();

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.answer', $wrongItem), ['answer' => '0'])
            ->assertOk()
            ->assertJsonPath('reveal', true);

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.acknowledge', $wrongItem))
            ->assertOk();

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.answer', $rightItem), [
                'answer' => (string) $rightItem->correct_answer,
            ])
            ->assertOk()
            ->assertJsonPath('session.phase', BasicsDrillSession::PHASE_FINAL_CORRECTION);

        $session->refresh();
        $correction = $session->items()->where('round', BasicsDrillItem::ROUND_CORRECTION)->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('student.basics-drill.answer', $correction), [
                'answer' => (string) $correction->correct_answer,
            ])
            ->assertOk()
            ->assertJsonPath('completed', true);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_admin_can_open_basics_drill_settings(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.basics-drill.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/BasicsDrill/Index'));
    }
}
