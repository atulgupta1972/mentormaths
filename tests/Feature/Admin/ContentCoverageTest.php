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
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_content_coverage_matrix(): void
    {
        [$grade, $board, $admin, $chapter] = $this->seedCoverageContent();

        $this->withoutVite()
            ->actingAs($admin)
            ->get(route('admin.questions.coverage', [
                'grade_level_id' => $grade->id,
                'board_id' => $board->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/ContentCoverage')
                ->has('coverage.chapters', 1)
                ->where('coverage.chapters.0.counts.practice', 1)
                ->where('coverageFilters.selected_grade_level_id', $grade->id)
                ->where('coverageFilters.selected_board_id', $board->id));
    }

    public function test_teacher_cannot_access_content_coverage(): void
    {
        [, , $admin] = $this->seedCoverageContent();
        $teacher = User::factory()->create(['role' => User::ROLE_TEACHER]);

        $this->actingAs($teacher)
            ->get(route('admin.questions.coverage'))
            ->assertRedirect(route('dashboard'));
    }

    /**
     * @return array{0: GradeLevel, 1: Board, 2: User, 3: SyllabusChapter}
     */
    private function seedCoverageContent(): array
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
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Properties',
            'sort_order' => 1,
        ]);

        Worksheet::query()->create([
            'title' => 'Practice 1',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $board, $admin, $chapter];
    }
}
