<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Services\AdminGradeContext;
use App\Services\UserGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConceptBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_concept_builder_lists_syllabus_and_build_link_when_pdf_ready(): void
    {
        $this->withoutVite();

        [$admin, $grade, $syllabusChapter, $upload] = $this->seedConceptBuilder(withPdf: true);

        $this->actingAs($admin)
            ->withSession([AdminGradeContext::SESSION_KEY => $grade->id])
            ->get(route('admin.concept-builder.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ConceptBuilder/Index')
                ->where('gradeLevel.id', $grade->id)
                ->has('chapters', 1)
                ->where('chapters.0.label', 'Ch 1 — Integers')
                ->where('chapters.0.has_pdf', true)
                ->where('chapters.0.primary_action_label', 'Build concepts')
                ->where('chapters.0.primary_action_url', route('admin.textbooks.concept-path', $upload))
            );
    }

    public function test_uploader_concept_builder_requires_upload_when_pdf_missing(): void
    {
        $this->withoutVite();

        [, $grade, , $upload] = $this->seedConceptBuilder(withPdf: false);
        $uploader = tap(User::factory()->create(['role' => User::ROLE_TEACHER]), function (User $user) {
            app(UserGroupService::class)->attachGroupByCode($user, User::ROLE_CONTENT_UPLOADER);
        });

        $this->actingAs($uploader)
            ->withSession([AdminGradeContext::SESSION_KEY => $grade->id])
            ->get(route('content.concept-builder.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ConceptBuilder/Index')
                ->where('uploaderMode', true)
                ->where('chapters.0.has_pdf', false)
                ->where('chapters.0.primary_action_url', route('content.textbooks.show', $upload))
            );
    }

    public function test_textbooks_index_shows_syllabus_chapter_label(): void
    {
        $this->withoutVite();

        [$admin, $grade] = $this->seedConceptBuilder(
            withPdf: true,
            storedTitle: 'Stale PDF title',
            storedNumber: 2,
            syllabusName: 'Integers',
            syllabusNumber: 'Ch 1',
        );

        $this->actingAs($admin)
            ->withSession([AdminGradeContext::SESSION_KEY => $grade->id])
            ->get(route('admin.textbooks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Textbooks/Index')
                ->where('chapters.0.label', 'Ch 1 — Integers')
                ->where('chapters.0.title', 'Integers')
            );
    }

    /**
     * @return array{0: User, 1: GradeLevel, 2: SyllabusChapter, 3: TextbookChapter}
     */
    private function seedConceptBuilder(
        bool $withPdf,
        string $storedTitle = 'Integers',
        int $storedNumber = 1,
        string $syllabusName = 'Integers',
        string $syllabusNumber = 'Ch 1',
    ): array {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'name' => $syllabusName,
            'chapter_number' => $syllabusNumber,
            'sort_order' => 1,
        ]);

        $book = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'code' => 'GP',
            'name' => 'Ganita Prakash Part I',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $upload = TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => $storedNumber,
            'title' => $storedTitle,
            'pdf_path' => $withPdf ? 'textbooks/chapters/demo.pdf' : null,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        return [$admin, $grade, $syllabusChapter, $upload];
    }
}
