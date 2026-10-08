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
use App\Services\PracticeSetCodeService;
use App\Services\UserGroupService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MmpSetTest extends TestCase
{
    use RefreshDatabase;

    public function test_mmp_codes_are_unique_and_use_mmp_prefix(): void
    {
        [$chapter] = $this->seedChapter();
        $service = app(PracticeSetCodeService::class);

        $this->assertSame('MMP711', $service->generateChapterMmp($chapter));

        Worksheet::query()->create([
            'title' => 'MMP set 1',
            'set_number' => 1,
            'set_code' => 'MMP711',
            'tier' => PracticeSetTier::CHAMPION,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'purpose' => WorksheetPurpose::PERFECTION,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $this->assertSame('MMP712', $service->generateChapterMmp($chapter));
    }

    public function test_admin_can_create_mmp_set_from_seed_json(): void
    {
        $this->withoutVite();

        [$chapter, $topic, $admin] = $this->seedChapter();

        $json = json_encode([
            'questions' => [
                [
                    'type' => 'fill_in_blank',
                    'topic' => $topic->name,
                    'question' => 'AH = 4 cm and BH = 3 cm in a right triangle. AB is ____ cm.',
                    'answer_format' => 'integer',
                    'correct_answer' => '5',
                    'method_hint' => 'Use Pythagoras on the two legs.',
                    'explanation' => 'AB = sqrt(16 + 9) = 5. Final answer = 5',
                    'difficulty' => 'Hard',
                ],
                [
                    'type' => 'mcq',
                    'topic' => $topic->name,
                    'question' => 'Which congruence statement matches the equal sides marked in the figure?',
                    'options' => ['△ABH ≅ △FDG', '△ABL ≅ △FED', 'None of these'],
                    'correct_index' => 0,
                    'method_hint' => 'Match corresponding vertices from equal sides.',
                    'explanation' => 'Corresponding vertices give △ABH ≅ △FDG. Final answer = △ABH ≅ △FDG',
                    'difficulty' => 'Hard',
                ],
                [
                    'type' => 'fill_in_blank',
                    'topic' => $topic->name,
                    'question' => 'If AH = GF = 6 cm and BH = 8 cm, AB is ____ cm.',
                    'answer_format' => 'integer',
                    'correct_answer' => '10',
                    'method_hint' => 'Pythagoras with legs 6 and 8.',
                    'explanation' => 'AB = sqrt(36 + 64) = 10. Final answer = 10',
                    'difficulty' => 'Hard',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($admin)
            ->post(route('admin.questions.store-mmp'), [
                'syllabus_chapter_id' => $chapter->id,
                'seed' => 'AH = 4, BH = 3, find AB and the twin triangle side.',
                'json' => $json,
            ])
            ->assertRedirect();

        $worksheet = Worksheet::query()->where('purpose', WorksheetPurpose::PERFECTION)->first();
        $this->assertNotNull($worksheet);
        $this->assertSame('MMP711', $worksheet->set_code);
        $this->assertTrue($worksheet->isPerfection());
        $this->assertSame(3, $worksheet->questions()->count());
        $this->assertSame(2, $worksheet->questions()->where('type', Question::TYPE_FILL_IN_BLANK)->count());
        $this->assertSame(1, $worksheet->questions()->where('type', Question::TYPE_MCQ)->count());

        $first = $worksheet->questions()->orderByPivot('sort_order')->first();
        $this->assertStringContainsString('AH = 4 cm', $first->question_text);
    }

    public function test_chapter_page_lists_mmp_sets(): void
    {
        $this->withoutVite();

        [$chapter, , $admin] = $this->seedChapter();

        Worksheet::query()->create([
            'title' => 'MMP711 — Mentormaths Perfection',
            'set_number' => 1,
            'set_code' => 'MMP711',
            'tier' => PracticeSetTier::CHAMPION,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'purpose' => WorksheetPurpose::PERFECTION,
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.questions.chapters.show', $chapter))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Questions/Hub/Topics')
                ->has('perfectionSets', 1)
                ->where('perfectionSets.0.set_code', 'MMP711')
                ->where('chapterTests', fn ($tests) => collect($tests)->every(
                    fn ($row) => ! str_starts_with((string) ($row['set_code'] ?? ''), 'MMP')
                ))
            );
    }

    public function test_mmp_prompt_requires_seed_or_draft(): void
    {
        $this->withoutVite();

        [$chapter, , $admin] = $this->seedChapter();

        $this->actingAs($admin)
            ->from(route('admin.questions.create-mmp', ['syllabus_chapter_id' => $chapter->id]))
            ->post(route('admin.questions.mmp-prompt'), [
                'syllabus_chapter_id' => $chapter->id,
                'seed' => 'too short',
                'total' => 8,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_admin_can_upload_rough_draft_photo_then_build_prompt(): void
    {
        $this->withoutVite();
        \Illuminate\Support\Facades\Storage::fake('public');

        [$chapter, , $admin] = $this->seedChapter();
        $file = \Illuminate\Http\UploadedFile::fake()->image('geometric-twins-draft.jpg', 800, 600);

        $this->actingAs($admin)
            ->post(route('admin.questions.mmp-upload-drafts'), [
                'syllabus_chapter_id' => $chapter->id,
                'drafts' => [$file],
            ])
            ->assertRedirect(route('admin.questions.create-mmp', ['syllabus_chapter_id' => $chapter->id]))
            ->assertSessionHas('mmp_draft_files');

        $drafts = session('mmp_draft_files');
        $this->assertIsArray($drafts);
        $this->assertCount(1, $drafts);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($drafts[0]['path']);

        $this->actingAs($admin)
            ->withSession(['mmp_draft_files' => $drafts])
            ->post(route('admin.questions.mmp-prompt'), [
                'syllabus_chapter_id' => $chapter->id,
                'seed' => '',
                'total' => 8,
            ])
            ->assertRedirect(route('admin.questions.create-mmp', ['syllabus_chapter_id' => $chapter->id]))
            ->assertSessionHas('mmp_cursor_prompt')
            ->assertSessionHas('mmp_draft_files');

        $prompt = session('mmp_cursor_prompt');
        $this->assertStringContainsString('Rough draft photos', $prompt);
        $this->assertStringContainsString('ATTACH THE ROUGH DRAFT', $prompt);

        // Drafts must stay in session after the prompt step (not flash-only).
        $this->assertCount(1, session('mmp_draft_files'));
    }

    /**
     * @return array{0: SyllabusChapter, 1: SyllabusTopic, 2: User}
     */
    private function seedChapter(): array
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
        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'name' => 'Geometric Twins',
            'chapter_number' => 'Ch 1',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Congruence of triangles',
            'sort_order' => 1,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        app(UserGroupService::class)->attachGroupByCode($admin, User::ROLE_ADMIN);

        return [$chapter, $topic, $admin];
    }
}
