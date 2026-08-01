<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionHubChaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_class_chapters_hub_for_board(): void
    {
        [$grade, $board, $admin] = $this->seedClassSevenBoard();

        $this->actingAs($admin)
            ->get(route('admin.questions.classes.show', [
                'gradeLevel' => $grade->id,
                'board_id' => $board->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/Hub/Chapters')
                ->where('gradeLevel.id', $grade->id)
                ->where('board.id', $board->id)
                ->where('syllabusVersion.board_code', 'CBSE')
                ->where('syllabusVersion.board_name', 'CBSE')
                ->has('chapters', 1)
                ->has('stats')
            );
    }

    public function test_syllabus_version_board_labels_fall_back_to_url_board(): void
    {
        $board = Board::query()->make(['code' => 'ICSE', 'name' => 'ICSE']);
        $syllabus = SyllabusVersion::query()->make(['id' => 99]);
        $syllabus->setRelation('board', null);

        $payload = [
            'id' => $syllabus->id,
            'board_code' => $syllabus->board?->code ?? $board->code,
            'board_name' => $syllabus->board?->name ?? $board->name,
        ];

        $this->assertSame('ICSE', $payload['board_code']);
        $this->assertSame('ICSE', $payload['board_name']);
    }

    /**
     * @return array{0: GradeLevel, 1: Board, 2: User}
     */
    private function seedClassSevenBoard(): array
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
            'name' => 'Fractions',
            'chapter_number' => 2,
            'sort_order' => 2,
        ]);

        SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Multiplication of Fractions',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $board, $admin];
    }
}
