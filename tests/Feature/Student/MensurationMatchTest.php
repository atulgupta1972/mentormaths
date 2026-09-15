<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\FormulaDrillSession;
use App\Models\GradeLevel;
use App\Models\MensurationMatchSession;
use App\Models\MensurationMatchSetting;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MensurationMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array{user: User, student: Student, enrollment: StudentEnrollment, grade: GradeLevel}
     */
    private function seedStudent(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
            'name' => 'Match Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo',
        ]);

        $enrollment = StudentEnrollment::query()->create([
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

        return compact('user', 'student', 'enrollment', 'grade');
    }

    public function test_admin_can_map_class_and_student_needs_tick_to_start(): void
    {
        ['user' => $studentUser, 'grade' => $grade] = $this->seedStudent();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.mensuration-match.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/MensurationMatch/Index'));

        $this->actingAs($admin)
            ->from(route('admin.mensuration-match.index'))
            ->put(route('admin.mensuration-match.update', $grade), [
                'enabled' => true,
                'perimeter_area_enabled' => true,
                'volume_enabled' => false,
            ])
            ->assertRedirect(route('admin.mensuration-match.index'));

        $this->assertDatabaseHas('mensuration_match_settings', [
            'grade_level_id' => $grade->id,
            'enabled' => 1,
            'perimeter_area_enabled' => 1,
            'volume_enabled' => 0,
        ]);

        $this->actingAs($studentUser)
            ->get(route('student.mensuration-match.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/MensurationMatch/Show')
                ->where('enabled', true)
                ->has('boards', 1));

        $this->actingAs($studentUser)
            ->from(route('student.mensuration-match.show'))
            ->post(route('student.mensuration-match.start'), [
                'board' => 'perimeter_area',
                'ready' => false,
            ])
            ->assertRedirect(route('student.mensuration-match.show'))
            ->assertSessionHasErrors('ready');

        $this->actingAs($studentUser)
            ->post(route('student.mensuration-match.start'), [
                'board' => 'perimeter_area',
                'ready' => true,
            ])
            ->assertRedirect();

        $session = MensurationMatchSession::query()->first();
        $this->assertNotNull($session);
        $this->assertSame('perimeter_area', $session->board);
        $this->assertSame(MensurationMatchSession::STATUS_IN_PROGRESS, $session->status);
    }

    public function test_student_can_match_formula_and_retry_wrong_tap(): void
    {
        ['user' => $user, 'student' => $student, 'enrollment' => $enrollment, 'grade' => $grade] = $this->seedStudent();

        MensurationMatchSetting::query()->create([
            'grade_level_id' => $grade->id,
            'enabled' => true,
            'perimeter_area_enabled' => true,
            'volume_enabled' => false,
        ]);

        $this->actingAs($user)
            ->post(route('student.mensuration-match.start'), [
                'board' => 'perimeter_area',
                'ready' => true,
            ]);

        $session = MensurationMatchSession::query()->where('student_id', $student->id)->firstOrFail();
        $itemKey = $session->item_keys[0];
        $catalog = collect(config('mensuration_match.items'))->keyBy('key');
        $expected = $catalog[$itemKey]['formula'];

        $this->actingAs($user)
            ->from(route('student.mensuration-match.play', $session))
            ->post(route('student.mensuration-match.answer', $session), [
                'item_key' => $itemKey,
                'formula' => 'WRONG',
            ])
            ->assertRedirect(route('student.mensuration-match.play', $session));

        $session->refresh();
        $this->assertSame([], $session->answers ?? []);
        $this->assertSame(0, $session->correct_count);

        $this->actingAs($user)
            ->from(route('student.mensuration-match.play', $session))
            ->post(route('student.mensuration-match.answer', $session), [
                'item_key' => $itemKey,
                'formula' => $expected,
            ])
            ->assertRedirect(route('student.mensuration-match.play', $session));

        $session->refresh();
        $this->assertTrue($session->answers[$itemKey]['correct']);
        $this->assertSame(1, $session->correct_count);
    }

    public function test_disabled_class_cannot_start(): void
    {
        ['user' => $user] = $this->seedStudent();

        $this->actingAs($user)
            ->get(route('student.mensuration-match.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('enabled', false)->has('boards', 0));

        $this->actingAs($user)
            ->from(route('student.mensuration-match.show'))
            ->post(route('student.mensuration-match.start'), [
                'board' => 'perimeter_area',
                'ready' => true,
            ])
            ->assertRedirect(route('student.mensuration-match.show'));
    }
}
