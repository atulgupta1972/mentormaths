<?php

namespace Tests\Feature\Admin;

use App\Jobs\ExtractTextbookChapterJob;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TextbookChapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_chapter_and_publish_extracted_sets(): void
    {
        Storage::fake('public');
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'items' => [
                                    [
                                        'id' => 'ex-1',
                                        'kind' => 'example',
                                        'label' => 'Example 1',
                                        'source_page' => 4,
                                        'question_text' => 'Find the 5th odd number using u_n = 2n - 1.',
                                        'correct_answer' => '9',
                                        'answer_format' => 'integer',
                                        'explanation' => 'u_5 = 2(5) - 1 = 9',
                                        'needs_diagram' => false,
                                        'include_in_mcq' => true,
                                        'include_in_written' => true,
                                        'mcq_options' => [
                                            ['text' => '9', 'is_correct' => true],
                                            ['text' => '10', 'is_correct' => false],
                                            ['text' => '7', 'is_correct' => false],
                                            ['text' => '11', 'is_correct' => false],
                                        ],
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$grade, $chapter, $admin] = $this->seedClassNineChapterEight();

        $samplePdf = base_path('tests/class 9/iemh108.pdf');
        $upload = file_exists($samplePdf)
            ? new UploadedFile($samplePdf, 'iemh108.pdf', 'application/pdf', null, true)
            : UploadedFile::fake()->create('chapter.pdf', 100, 'application/pdf');

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.textbooks.store'), [
                'book_name' => 'Ganita Manjari Part I',
                'book_code' => 'iemh1',
                'syllabus_chapter_id' => $chapter->id,
                'pdf' => $upload,
            ])
            ->assertRedirect();

        $textbookChapter = TextbookChapter::query()->firstOrFail();
        $this->assertContains($textbookChapter->status, [
            TextbookChapter::STATUS_DRAFT,
            TextbookChapter::STATUS_EXTRACTING,
            TextbookChapter::STATUS_REVIEW,
        ]);

        if ($textbookChapter->status !== TextbookChapter::STATUS_REVIEW) {
            (new ExtractTextbookChapterJob($textbookChapter->id))->handle(app(\App\Services\TextbookChapterExtractionService::class));
            $textbookChapter->refresh();
        }

        $this->assertSame(TextbookChapter::STATUS_REVIEW, $textbookChapter->status);
        $this->assertNotEmpty($textbookChapter->extraction_items);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish', $textbookChapter), [
                'items' => $textbookChapter->extraction_items,
            ])
            ->assertRedirect();

        $textbookChapter->refresh();
        $this->assertSame(TextbookChapter::STATUS_PUBLISHED, $textbookChapter->status);
        $this->assertNotNull($textbookChapter->mcq_worksheet_id);
        $this->assertNotNull($textbookChapter->written_worksheet_id);

        $mcq = Worksheet::query()->findOrFail($textbookChapter->mcq_worksheet_id);
        $written = Worksheet::query()->findOrFail($textbookChapter->written_worksheet_id);

        $this->assertSame('C9-TB08-M', $mcq->set_code);
        $this->assertSame('C9-TB08-W', $written->set_code);
        $this->assertSame(1, $mcq->questions()->count());
        $this->assertSame(1, $written->questions()->count());
    }

    public function test_admin_can_view_textbook_chapter_show_page(): void
    {
        Storage::fake('public');
        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Manjari Part I',
            'code' => 'iemh1',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_EXTRACTING,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.textbooks.show', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/Show')
                ->has('chapter', fn ($chapterPage) => $chapterPage
                    ->where('id', $chapter->id)
                    ->where('status', TextbookChapter::STATUS_EXTRACTING)
                    ->has('book')
                    ->etc()
                )
            );
    }

    public function test_extraction_processes_chapter_in_page_batches(): void
    {
        $samplePdf = base_path('tests/class 9/iemh108.pdf');
        if (! file_exists($samplePdf)) {
            $this->markTestSkipped('Sample chapter PDF is not available.');
        }

        Storage::fake('public');
        Storage::disk('public')->put('textbooks/test/chapter.pdf', file_get_contents($samplePdf));

        config([
            'services.openai.api_key' => 'test-key',
            'services.openai.textbook_extraction_pages_per_batch' => 3,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'items' => [
                                    [
                                        'id' => 'ex-batch',
                                        'kind' => 'example',
                                        'label' => 'Example 1',
                                        'source_page' => 4,
                                        'question_text' => 'Find the 5th term.',
                                        'correct_answer' => '9',
                                        'answer_format' => 'integer',
                                        'explanation' => 'Substitute n = 5.',
                                        'needs_diagram' => false,
                                        'include_in_mcq' => true,
                                        'include_in_written' => true,
                                        'mcq_options' => [
                                            ['text' => '9', 'is_correct' => true],
                                            ['text' => '10', 'is_correct' => false],
                                            ['text' => '7', 'is_correct' => false],
                                            ['text' => '11', 'is_correct' => false],
                                        ],
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ]),
        ]);

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Manjari Part I',
            'code' => 'iemh1',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/test/chapter.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $items = app(\App\Services\TextbookChapterExtractionService::class)->extract($chapter);

        $this->assertNotEmpty($items);
        Http::assertSentCount(9);
    }

    /**
     * @return array{0: GradeLevel, 1: SyllabusChapter, 2: User}
     */
    private function seedClassNineChapterEight(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Sequences and Progressions',
            'chapter_number' => 'Ch 8',
            'sort_order' => 8,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $chapter, $admin];
    }
}
