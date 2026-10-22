<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\MmmCard;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardPlayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFirstTermMarksEntered::class,
            \App\Http\Middleware\EnsureChapterTermsMapped::class,
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureMensurationMatchComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);
    }

    public function test_student_can_open_balance_equation_cards(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $chapter = $this->makeChapter();

        foreach ([
            'balance-the-equation',
            'balance-equation-basic-2',
            'balance-equation-basic-3',
            'balance-equation-basic-4',
        ] as $slug) {
            $card = MmmCard::query()->where('slug', $slug)->firstOrFail();
            $card->syllabusChapters()->syncWithoutDetaching([$chapter->id]);

            $this->actingAs($user)
                ->get(route('student.mmm-cards.show', $card))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Student/MmmCards/Play')
                    ->where('card.slug', $slug)
                    ->where('is_staff_preview', false)
                    ->where('back_url', route('dashboard')));
        }
    }

    public function test_student_cannot_open_unlinked_card(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-3')->firstOrFail();

        $this->actingAs($user)
            ->get(route('student.mmm-cards.show', $card))
            ->assertNotFound();
    }

    private function makeChapter(): SyllabusChapter
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);

        return SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'chapter_number' => 1,
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
    }
}
