<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ChapterHead;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ConceptPathStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConceptOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_sees_concept_overview_matrix_and_drill_down(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $cbse = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $icse = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);
        $class6 = GradeLevel::query()->create(['name' => 'Class 6', 'sort_order' => 6, 'is_active' => true]);
        $class7 = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $head = ChapterHead::query()->create([
            'name' => 'Algebraic Expressions',
            'branch' => 'algebra',
            'sort_order' => 1,
        ]);

        $sv6 = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $cbse->id,
            'grade_level_id' => $class6->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);
        $ch6 = SyllabusChapter::query()->create([
            'syllabus_version_id' => $sv6->id,
            'chapter_head_id' => $head->id,
            'chapter_number' => '4',
            'name' => 'Basic Algebraic Expressions',
            'sort_order' => 1,
        ]);

        $sv7 = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $icse->id,
            'grade_level_id' => $class7->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);
        SyllabusChapter::query()->create([
            'syllabus_version_id' => $sv7->id,
            'chapter_head_id' => $head->id,
            'chapter_number' => '5',
            'name' => 'Algebraic Expressions',
            'sort_order' => 1,
        ]);

        $textbook = Textbook::query()->create([
            'name' => 'Demo Book',
            'code' => 'DEMO',
            'board_id' => $cbse->id,
            'grade_level_id' => $class6->id,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $ch6->id,
            'chapter_number' => 4,
            'title' => 'Basic Algebraic Expressions',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'concept_path_status' => ConceptPathStatus::APPROVED,
            'concept_path_items' => [['prompt' => 'What is a term?']],
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.concept-overview.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConceptOverview/Index')
                ->has('rows', 1)
                ->has('branches', 1)
                ->where('branches.0.key', 'algebra')
                ->where('rows.0.name', 'Algebraic Expressions')
                ->where('summary.ready', 1)
                ->where('summary.to_upload', 1)
                ->where('summary.chapters_total', 2)
                ->where('summary.chapters_tagged', 2)
                ->where('summary.chapters_on_map', 2)
                ->where('summary.chapters_untagged', 0)
                ->where('summary.matched', true));

        $this->actingAs($admin)
            ->get(route('admin.concept-overview.show', $head))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConceptOverview/Show')
                ->where('chapter_head.name', 'Algebraic Expressions')
                ->has('ladder', 2));
    }

    public function test_draft_class7_triangles_appears_under_geometry(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $cbse = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        // Duplicate class numbers — overview must still match the real Class 7 chapter.
        GradeLevel::query()->create(['name' => 'Class 7 Debug', 'sort_order' => 1, 'is_active' => true]);
        $class7 = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $head = ChapterHead::query()->create([
            'name' => 'Triangles',
            'branch' => 'geometry',
            'sort_order' => 1,
        ]);

        $sv = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $cbse->id,
            'grade_level_id' => $class7->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_DRAFT,
        ]);
        SyllabusChapter::query()->create([
            'syllabus_version_id' => $sv->id,
            'chapter_head_id' => $head->id,
            'chapter_number' => 'P2- Ch 1',
            'name' => 'Geometric Twins',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.concept-overview.index', [
                'class' => 7,
                'board' => $cbse->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConceptOverview/Index')
                ->where('branches.0.key', 'geometry')
                ->where('rows.0.name', 'Triangles')
                ->where('summary.chapters_total', 1)
                ->where('summary.chapters_on_map', 1)
                ->where('summary.matched', true)
                ->where('rows.0.cells.7.best_status', 'to_upload')
                ->where('rows.0.cells.7.boards.CBSE.chapter_name', 'Geometric Twins')
                ->has('class_cards', 1)
                ->where('class_cards.0.chapter_name', 'Geometric Twins')
                ->where('class_cards.0.chapter_head', 'Triangles'));
    }

    public function test_draft_on_canonical_class7_wins_over_sibling_grade_syllabus(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $cbse = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        // Same class number, different grade rows (prod can have Class 7 Debug + Class 7).
        $debug7 = GradeLevel::query()->create(['name' => 'Class 7 Debug', 'sort_order' => 1, 'is_active' => true]);
        $class7 = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $head = ChapterHead::query()->create([
            'name' => 'Triangles',
            'branch' => 'geometry',
            'sort_order' => 1,
        ]);

        $publishedDebug = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $cbse->id,
            'grade_level_id' => $debug7->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_PUBLISHED,
        ]);
        SyllabusChapter::query()->create([
            'syllabus_version_id' => $publishedDebug->id,
            'chapter_head_id' => $head->id,
            'chapter_number' => '1',
            'name' => 'Old Triangles Title',
            'sort_order' => 1,
        ]);

        $draft = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $cbse->id,
            'grade_level_id' => $class7->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_DRAFT,
        ]);
        SyllabusChapter::query()->create([
            'syllabus_version_id' => $draft->id,
            'chapter_head_id' => $head->id,
            'chapter_number' => 'P2- Ch 1',
            'name' => 'Geometric Twins',
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.concept-overview.index', [
                'class' => 7,
                'board' => $cbse->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConceptOverview/Index')
                // Distinct titles from sibling Class-7 grades both stay on the map.
                ->where('summary.chapters_total', 2)
                ->has('class_cards', 2)
                ->where('rows.0.cells.7.boards.CBSE.entries', fn ($entries) => collect($entries)->contains(
                    fn ($e) => ($e['chapter_name'] ?? null) === 'Geometric Twins'
                )));
    }

    public function test_two_triangles_chapters_both_appear_in_class7_cell(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $cbse = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $class7 = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths', 'is_active' => true]);
        $head = ChapterHead::query()->create([
            'name' => 'Triangles',
            'branch' => 'geometry',
            'sort_order' => 1,
        ]);

        $sv = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $cbse->id,
            'grade_level_id' => $class7->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_DRAFT,
        ]);
        SyllabusChapter::query()->create([
            'syllabus_version_id' => $sv->id,
            'chapter_head_id' => $head->id,
            'chapter_number' => 'P2- Ch 1',
            'name' => 'Geometric Twins',
            'sort_order' => 1,
        ]);
        SyllabusChapter::query()->create([
            'syllabus_version_id' => $sv->id,
            'chapter_head_id' => $head->id,
            'chapter_number' => 'P1- Ch 3',
            'name' => 'A Tale of Three Intersecting Lines',
            'sort_order' => 2,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.concept-overview.index', [
                'class' => 7,
                'board' => $cbse->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConceptOverview/Index')
                ->where('summary.chapters_total', 2)
                ->has('class_cards', 2)
                ->has('rows.0.cells.7.boards.CBSE.entries', 2)
                ->where('rows.0.cells.7.boards.CBSE.entries.0.chapter_name', 'Geometric Twins')
                ->where('rows.0.cells.7.boards.CBSE.entries.1.chapter_name', 'A Tale of Three Intersecting Lines'));
    }
}
