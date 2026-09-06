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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_concept_path_preview_saves_draft_without_huge_session_flash(): void
    {
        $this->withoutVite();

        [$admin, , , $upload] = $this->seedConceptBuilder(withPdf: true);

        $json = json_encode([
            'chapter_title' => 'Integers',
            'cards' => [
                [
                    'step' => 1,
                    'type' => 'teach',
                    'title' => 'What is an integer?',
                    'body' => 'Integers are whole numbers and their negatives.',
                    'example' => '-3, 0, 5',
                ],
                [
                    'step' => 2,
                    'type' => 'check',
                    'title' => 'Quick check',
                    'questions' => [
                        [
                            'question_type' => 'fill_blank',
                            'question' => 'The opposite of 3 is ____',
                            'correct_answer' => '-3',
                            'answer_format' => 'integer',
                            'explanation' => 'Opposites sum to zero.',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($admin)
            ->from(route('admin.textbooks.concept-path', $upload))
            ->post(route('admin.textbooks.concept-path.save', $upload), [
                'chapter_title' => 'Integers',
                'payload_json' => $json,
            ])
            ->assertRedirect(route('admin.textbooks.concept-path', $upload))
            ->assertSessionHas('success');

        $upload->refresh();
        $this->assertSame('draft', $upload->concept_path_status);
        $this->assertCount(2, $upload->concept_path_items['cards'] ?? []);

        $this->actingAs($admin)
            ->get(route('admin.textbooks.concept-path', $upload))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Textbooks/ConceptPath')
                ->where('conceptPath.status', 'draft')
                ->has('conceptPath.cards', 2)
            );
    }

    public function test_approved_concept_path_can_be_run_from_builder(): void
    {
        $this->withoutVite();

        [$admin, $grade, , $upload] = $this->seedConceptBuilder(withPdf: true);

        $upload->update([
            'concept_path_status' => 'approved',
            'concept_path_items' => [
                'chapter_title' => 'Integers',
                'cards' => [
                    [
                        'step' => 1,
                        'type' => 'teach',
                        'title' => 'Integers',
                        'body' => 'Whole numbers and negatives.',
                        'approved' => true,
                    ],
                    [
                        'step' => 2,
                        'type' => 'check',
                        'title' => 'Quick check',
                        'approved' => true,
                        'questions' => [
                            [
                                'question_type' => 'fill_blank',
                                'question' => 'Opposite of 4 is ____',
                                'correct_answer' => '-4',
                                'answer_format' => 'integer',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->withSession([AdminGradeContext::SESSION_KEY => $grade->id])
            ->get(route('admin.concept-builder.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ConceptBuilder/Index')
                ->where('chapters.0.is_approved', true)
                ->where('chapters.0.run_url', route('admin.textbooks.concept-path.play', $upload))
            );

        $this->actingAs($admin)
            ->get(route('admin.textbooks.concept-path.play', $upload))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Textbooks/ConceptPathPlay')
                ->has('path.cards', 2)
            );
    }

    public function test_concept_path_card_diagram_can_be_uploaded_and_removed(): void
    {
        $this->withoutVite();
        Storage::fake('public');

        [$admin, , , $upload] = $this->seedConceptBuilder(withPdf: true);

        $upload->update([
            'concept_path_status' => 'draft',
            'concept_path_items' => [
                'chapter_title' => 'Integers',
                'cards' => [
                    [
                        'step' => 1,
                        'type' => 'teach',
                        'title' => 'Number line',
                        'body' => 'See Fig 1.2 on the number line.',
                        'example' => 'Fig 1.2',
                        'approved' => true,
                    ],
                    [
                        'step' => 2,
                        'type' => 'check',
                        'title' => 'Quick check',
                        'approved' => true,
                        'questions' => [
                            [
                                'question_type' => 'fill_blank',
                                'question' => 'Opposite of 2 is ____',
                                'correct_answer' => '-2',
                                'answer_format' => 'integer',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.textbooks.concept-path', $upload))
            ->post(route('admin.textbooks.concept-path.replace-diagram', $upload), [
                'card_index' => 0,
                'diagram' => UploadedFile::fake()->image('fig.png', 400, 300),
            ])
            ->assertRedirect(route('admin.textbooks.concept-path', $upload))
            ->assertSessionHas('success');

        $upload->refresh();
        $path = $upload->concept_path_items['cards'][0]['diagram_path'] ?? null;
        $this->assertNotEmpty($path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($admin)
            ->from(route('admin.textbooks.concept-path', $upload))
            ->post(route('admin.textbooks.concept-path.remove-diagram', $upload), [
                'card_index' => 0,
            ])
            ->assertRedirect(route('admin.textbooks.concept-path', $upload));

        $upload->refresh();
        $this->assertArrayNotHasKey('diagram_path', $upload->concept_path_items['cards'][0]);
    }

    public function test_concept_path_attaches_pdf_page_from_figure_page(): void
    {
        $this->withoutVite();
        Storage::fake('public');

        [$admin, , , $upload] = $this->seedConceptBuilder(withPdf: true);

        Storage::disk('public')->put($upload->pdf_path, '%PDF-1.4');
        Storage::disk('public')->put('textbook-chapter-pages/'.$upload->id.'/page-2.png', 'fake-png-bytes');

        $payload = [
            'chapter_title' => 'Lines',
            'cards' => [
                [
                    'step' => 1,
                    'type' => 'teach',
                    'title' => 'Transversal',
                    'body' => 'A transversal crosses two lines.',
                    'example' => 'See Fig 5.14',
                    'figure_page' => 2,
                    'approved' => true,
                ],
                [
                    'step' => 2,
                    'type' => 'check',
                    'title' => 'Quick check',
                    'approved' => true,
                    'questions' => [
                        [
                            'question_type' => 'fill_blank',
                            'question' => 'A transversal forms ____ angles.',
                            'correct_answer' => '8',
                            'answer_format' => 'integer',
                        ],
                    ],
                ],
            ],
        ];

        $this->actingAs($admin)
            ->from(route('admin.textbooks.concept-path', $upload))
            ->post(route('admin.textbooks.concept-path.save', $upload), [
                'chapter_title' => 'Lines',
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect();

        $upload->refresh();
        $this->assertNotEmpty($upload->concept_path_items['cards'][0]['diagram_path'] ?? null);
        $this->assertSame(2, $upload->concept_path_items['cards'][0]['figure_page'] ?? null);
        Storage::disk('public')->assertExists($upload->concept_path_items['cards'][0]['diagram_path']);
    }

    public function test_pull_pdf_figures_saves_and_attaches_without_manual_upload(): void
    {
        $this->withoutVite();
        Storage::fake('public');

        [$admin, , , $upload] = $this->seedConceptBuilder(withPdf: true);
        Storage::disk('public')->put($upload->pdf_path, '%PDF-1.4');
        Storage::disk('public')->put('textbook-chapter-pages/'.$upload->id.'/page-2.png', 'fake-png-bytes');

        $payload = [
            'chapter_title' => 'Lines',
            'cards' => [
                [
                    'step' => 1,
                    'type' => 'teach',
                    'title' => 'Linear pair',
                    'body' => 'Two angles on a straight line.',
                    'example' => 'Fig 5.2',
                    'figure_page' => 2,
                ],
                [
                    'step' => 2,
                    'type' => 'check',
                    'title' => 'Check',
                    'questions' => [
                        [
                            'question_type' => 'fill_blank',
                            'question' => 'Linear pair sums to ____',
                            'correct_answer' => '180',
                            'answer_format' => 'integer',
                        ],
                    ],
                ],
            ],
        ];

        $this->actingAs($admin)
            ->from(route('admin.textbooks.concept-path', $upload))
            ->post(route('admin.textbooks.concept-path.pull-pdf-figures', $upload), [
                'chapter_title' => 'Lines',
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $upload->refresh();
        $this->assertSame('draft', $upload->concept_path_status);
        $this->assertNotEmpty($upload->concept_path_items['cards'][0]['diagram_path'] ?? null);
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
