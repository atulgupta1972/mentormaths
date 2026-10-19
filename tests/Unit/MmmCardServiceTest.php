<?php

namespace Tests\Unit;

use App\Models\MmmCard;
use App\Models\MmmCardExercise;
use App\Models\SyllabusChapter;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_scoring_demo_is_seeded_published(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(7, $card->exercises()->count());
        $this->assertTrue((bool) $card->exercises()->orderBy('sort_order')->first()?->is_demo);
    }

    public function test_variable_sample_accepts_x_table_and_equation(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => 'x', 'total' => '2x'],
                'wrong' => ['marks' => '-1', 'count' => '20-x', 'total' => 'x-20'],
            ],
            'grand_total' => '25',
            'equation' => '3x - 20 = 25',
            'x_value' => '15',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['cell_results']));
    }

    public function test_derived_wrong_count_exercise_checks_out(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 5)->firstOrFail();
        $service = app(MmmCardService::class);

        // Kabir: 5 correct, attempted 7 → 2 wrong; +2 / −1 → total 8
        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => '5', 'total' => '10'],
                'wrong' => ['marks' => '-1', 'count' => '2', 'total' => '-2'],
            ],
            'grand_total' => '8',
        ]);

        $this->assertTrue($result['ok']);
    }

    public function test_check_submission_marks_wrong_cells(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->firstOrFail();
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => '10', 'total' => '20'],
                'wrong' => ['marks' => '-1', 'count' => '5', 'total' => '0'],
            ],
            'grand_total' => '15',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['cell_results']['correct.marks']);
        $this->assertFalse($result['cell_results']['wrong.total']);
        $this->assertTrue($result['cell_results']['grand_total']);
    }

    public function test_check_submission_accepts_full_correct_table(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->firstOrFail();
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => '10', 'total' => '20'],
                'wrong' => ['marks' => '−1', 'count' => '5', 'total' => '-5'],
            ],
            'grand_total' => '15',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue(collect($result['cell_results'])->every(fn ($ok) => $ok === true));
    }

    public function test_admin_can_link_card_to_syllabus_chapter(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();

        $year = \App\Models\AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = \App\Models\Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = \App\Models\GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = \App\Models\Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $version = \App\Models\SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.mmm-cards.sync-chapters', $card), [
                'syllabus_chapter_ids' => [$chapter->id],
                'mode' => 'add',
            ])
            ->assertRedirect();

        $this->assertTrue($card->fresh()->syllabusChapters->contains('id', $chapter->id));

        $ctas = app(MmmCardService::class)->ctasForSyllabusChapters(null, [$chapter->id]);
        $this->assertCount(1, $ctas[$chapter->id] ?? []);
        $this->assertSame('Marks Scoring table', $ctas[$chapter->id][0]['heading']);
    }
}
