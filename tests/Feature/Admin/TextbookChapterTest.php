<?php

namespace Tests\Feature\Admin;

use App\Jobs\ExtractTextbookChapterJob;
use App\Mail\TextbookChapterExtracted;
use App\Mail\TextbookChapterExtractionFailed;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TextbookChapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_chapter_import_mcq_json_and_publish(): void
    {
        Storage::fake('public');

        [$grade, $chapter, $admin] = $this->seedClassNineChapterEight();

        $upload = UploadedFile::fake()->create('chapter.pdf', 100, 'application/pdf');

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.textbooks.store'), [
                'book_name' => 'Ganita Prakash Part I',
                'book_code' => 'GP',
                'syllabus_chapter_id' => $chapter->id,
                'pdf' => $upload,
            ])
            ->assertRedirect();

        $textbookChapter = TextbookChapter::query()->firstOrFail();
        $this->assertSame(TextbookChapter::STATUS_DRAFT, $textbookChapter->status);

        $json = json_encode([
            'questions' => [
                [
                    'topic' => 'Explicit rule',
                    'question' => 'Find the 5th term of tₙ = 2n − 1.',
                    'options' => ['7', '9', '11', '13'],
                    'correct_index' => 1,
                    'hint' => 'Substitute n = 5.',
                    'explanation' => '2(5) − 1 = 9',
                    'difficulty' => 'Easy',
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.import-mcq', $textbookChapter), ['json' => $json])
            ->assertRedirect(route('admin.textbooks.show', $textbookChapter));

        $textbookChapter->refresh();
        $this->assertSame(TextbookChapter::STATUS_REVIEW, $textbookChapter->status);
        $this->assertCount(1, $textbookChapter->extraction_items);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish', $textbookChapter), [
                'items' => $textbookChapter->extraction_items,
            ])
            ->assertRedirect();

        $textbookChapter->refresh();
        $this->assertSame(TextbookChapter::STATUS_PUBLISHED, $textbookChapter->status);
        $this->assertNotNull($textbookChapter->mcq_worksheet_id);
        $this->assertNull($textbookChapter->written_worksheet_id);

        $mcq = Worksheet::query()->findOrFail($textbookChapter->mcq_worksheet_id);
        $this->assertSame('C9-GP-CH08-M', $mcq->set_code);
        $this->assertSame(1, $mcq->questions()->count());
        $this->assertSame([$mcq->id], $textbookChapter->mcq_worksheet_ids);
    }

    public function test_admin_can_import_mcq_zip_with_diagram_and_publish(): void
    {
        Storage::fake('public');

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part II',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $zipPath = $this->createMcqImportZip([
            'questions' => [[
                'topic' => 'Bar graphs',
                'question' => 'Which month had the highest sales?',
                'chart' => 'Bar chart — Jan: 120, Feb: 180',
                'options' => ['Jan', 'Feb', 'Mar', 'Apr'],
                'correct_index' => 1,
                'explanation' => 'Feb is highest. Answer: B',
                'difficulty' => 'Easy',
                'diagram_file' => 'chart1.png',
            ]],
        ], [
            'chart1.png' => $this->minimalPngBytes(),
        ]);

        $zip = new UploadedFile($zipPath, 'data-handling.zip', 'application/zip', null, true);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.import-mcq-zip', $textbookChapter), ['pack' => $zip])
            ->assertRedirect(route('admin.textbooks.show', $textbookChapter));

        $textbookChapter->refresh();
        $this->assertSame(TextbookChapter::STATUS_REVIEW, $textbookChapter->status);
        $this->assertCount(1, $textbookChapter->extraction_items);
        $this->assertNotEmpty($textbookChapter->extraction_items[0]['diagram_staging_path'] ?? null);
        $this->assertTrue(
            Storage::disk('public')->exists($textbookChapter->extraction_items[0]['diagram_staging_path']),
        );

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish', $textbookChapter), [
                'items' => $textbookChapter->extraction_items,
            ])
            ->assertRedirect();

        $textbookChapter->refresh();
        $mcq = Worksheet::query()->findOrFail($textbookChapter->mcq_worksheet_id);
        $question = $mcq->questions()->first();
        $this->assertNotNull($question);
        $this->assertNotNull($question->diagram_path);
        $this->assertTrue(Storage::disk('public')->exists($question->diagram_path));
    }

    public function test_admin_can_replace_item_diagram_after_publish(): void
    {
        Storage::fake('public');

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part II',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'id' => 'mcq-1',
                'label' => 'Bar graphs · Q1',
                'question_text' => 'Which month had the highest sales?',
                'approved' => true,
                'include_in_mcq' => true,
                'include_in_written' => false,
                'mcq_options' => [
                    ['text' => 'Jan', 'is_correct' => false],
                    ['text' => 'Feb', 'is_correct' => true],
                    ['text' => 'Mar', 'is_correct' => false],
                    ['text' => 'Apr', 'is_correct' => false],
                ],
                'diagram_staging_path' => 'textbooks/1/chapters/8/import-diagrams/old.png',
            ]],
            'mcq_set_plan' => [[
                'set_code' => 'C9-GP-CH08-M',
                'q_from' => 1,
                'q_to' => 1,
                'description' => '',
            ]],
        ]);

        Storage::disk('public')->put('textbooks/1/chapters/8/import-diagrams/old.png', 'old');

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish', $textbookChapter), [
                'items' => $textbookChapter->extraction_items,
            ])
            ->assertRedirect();

        $replacement = UploadedFile::fake()->image('rocket-chart.png');

        $this->actingAs($admin)
            ->post(route('admin.textbooks.replace-diagram', $textbookChapter), [
                'item_index' => 0,
                'diagram' => $replacement,
            ])
            ->assertRedirect(route('admin.textbooks.show', $textbookChapter));

        $textbookChapter->refresh();
        $newPath = $textbookChapter->extraction_items[0]['diagram_staging_path'] ?? null;
        $this->assertNotSame('textbooks/1/chapters/8/import-diagrams/old.png', $newPath);
        $this->assertTrue(Storage::disk('public')->exists($newPath));

        $question = Worksheet::query()->findOrFail($textbookChapter->mcq_worksheet_id)->questions()->first();
        $this->assertNotNull($question->diagram_path);
        $this->assertTrue(Storage::disk('public')->exists($question->diagram_path));
    }

    public function test_admin_can_import_fill_blank_from_mcq_and_publish_fill_blank_written(): void
    {
        Storage::fake('public');

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'id' => 'mcq-1',
                'label' => 'Mean · Q1',
                'question_text' => 'Runs 67, 55, 18 and 35 — what is the total?',
                'correct_answer' => '175',
                'approved' => true,
                'include_in_mcq' => true,
                'include_in_written' => false,
                'mcq_options' => [
                    ['text' => '128', 'is_correct' => false],
                    ['text' => '175', 'is_correct' => true],
                    ['text' => '190', 'is_correct' => false],
                    ['text' => '200', 'is_correct' => false],
                ],
            ]],
            'mcq_set_plan' => [[
                'set_code' => 'C9-GP-CH08-M',
                'q_from' => 1,
                'q_to' => 1,
                'description' => '',
            ]],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish', $textbookChapter), [
                'items' => $textbookChapter->extraction_items,
            ])
            ->assertRedirect();

        $textbookChapter->refresh();
        $mcqWorksheetId = $textbookChapter->mcq_worksheet_id;

        $fillBlankJson = json_encode([
            'questions' => [[
                'source_index' => 1,
                'topic' => 'Mean',
                'question' => 'Runs 67, 55, 18 and 35 — the total is ____.',
                'answer_format' => 'integer',
                'correct_answer' => '175',
                'method_hint' => 'Add all values.',
                'explanation' => '67+55+18+35 = 175.',
                'difficulty' => 'Easy',
            ]],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.import-fill-blank', $textbookChapter), ['json' => $fillBlankJson])
            ->assertRedirect();

        $textbookChapter->refresh();
        $this->assertSame('Runs 67, 55, 18 and 35 — the total is ____.', $textbookChapter->extraction_items[0]['fill_blank_question_text']);
        $this->assertTrue($textbookChapter->extraction_items[0]['include_in_written']);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish-fill-blank-written', $textbookChapter))
            ->assertRedirect();

        $textbookChapter->refresh();
        $this->assertSame($mcqWorksheetId, $textbookChapter->mcq_worksheet_id);
        $this->assertNotNull($textbookChapter->fill_blank_worksheet_id);
        $this->assertNotNull($textbookChapter->written_worksheet_id);

        $fillBlank = Worksheet::query()->findOrFail($textbookChapter->fill_blank_worksheet_id);
        $written = Worksheet::query()->findOrFail($textbookChapter->written_worksheet_id);

        $this->assertSame('C9-GP-CH08-F', $fillBlank->set_code);
        $this->assertSame('C9-GP-CH08-W', $written->set_code);
        $this->assertSame(1, $fillBlank->questions()->count());
        $this->assertSame(1, $written->questions()->count());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $images
     */
    private function createMcqImportZip(array $payload, array $images = []): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'textbook-mcq-');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('questions.json', json_encode($payload, JSON_THROW_ON_ERROR));

        foreach ($images as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();

        return $zipPath;
    }

    private function minimalPngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ) ?: '';
    }

    public function test_publish_splits_mcq_chapter_using_custom_set_plan(): void
    {
        Storage::fake('public');

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $items = [];
        for ($i = 1; $i <= 70; $i++) {
            $items[] = [
                'id' => "mcq-{$i}",
                'kind' => 'exercise',
                'label' => "Q{$i}",
                'question_text' => "Question {$i}?",
                'correct_answer' => (string) $i,
                'approved' => true,
                'include_in_mcq' => true,
                'include_in_written' => false,
                'mcq_options' => [
                    ['text' => (string) $i, 'is_correct' => true],
                    ['text' => '0', 'is_correct' => false],
                    ['text' => '1', 'is_correct' => false],
                    ['text' => '2', 'is_correct' => false],
                ],
            ];
        }

        $setPlan = [
            ['set_code' => 'C9-GP-CH08-M1', 'q_from' => 1, 'q_to' => 15, 'description' => 'AP'],
            ['set_code' => 'C9-GP-CH08-M2', 'q_from' => 16, 'q_to' => 36, 'description' => 'AP'],
            ['set_code' => 'C9-GP-CH08-M3', 'q_from' => 37, 'q_to' => 70, 'description' => 'GP'],
        ];

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_REVIEW,
            'extraction_items' => $items,
            'mcq_set_plan' => $setPlan,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish', $textbookChapter), [
                'items' => $items,
                'mcq_set_plan' => $setPlan,
            ])
            ->assertRedirect();

        $textbookChapter->refresh();

        $this->assertSame(TextbookChapter::STATUS_PUBLISHED, $textbookChapter->status);
        $this->assertCount(3, $textbookChapter->mcq_worksheet_ids);

        $worksheets = Worksheet::query()
            ->whereIn('id', $textbookChapter->mcq_worksheet_ids)
            ->orderBy('set_number')
            ->get();

        $this->assertSame(
            ['C9-GP-CH08-M1', 'C9-GP-CH08-M2', 'C9-GP-CH08-M3'],
            $worksheets->pluck('set_code')->all(),
        );
        $this->assertSame([15, 21, 34], $worksheets->map(fn (Worksheet $ws) => $ws->questions()->count())->all());
        $this->assertSame('Sequences and Progressions — Textbook MCQ — AP', $worksheets->first()->title);
        $this->assertSame('Sequences and Progressions — Textbook MCQ — GP', $worksheets->last()->title);
    }

    public function test_import_mcq_json_defaults_to_single_set_plan(): void
    {
        Storage::fake('public');

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $questions = [];
        for ($i = 1; $i <= 5; $i++) {
            $questions[] = [
                'topic' => 'AP',
                'question' => "Question {$i}?",
                'options' => ['1', '2', '3', '4'],
                'correct_index' => 0,
                'explanation' => 'Because',
                'difficulty' => 'Easy',
            ];
        }

        $json = json_encode([
            'questions' => $questions,
            'set_plan' => [
                ['set_code' => 'C9-GP-CH08-M1', 'q_from' => 1, 'q_to' => 3, 'description' => 'AP'],
                ['set_code' => 'C9-GP-CH08-M2', 'q_from' => 4, 'q_to' => 5, 'description' => 'GP'],
            ],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.import-mcq', $textbookChapter), ['json' => $json])
            ->assertRedirect();

        $textbookChapter->refresh();

        $this->assertCount(5, $textbookChapter->extraction_items);
        $this->assertSame([
            ['set_code' => 'C9-GP-CH08-M', 'q_from' => 1, 'q_to' => 5, 'description' => ''],
        ], $textbookChapter->mcq_set_plan);
    }

    public function test_admin_can_view_textbook_chapter_show_page(): void
    {
        Storage::fake('public');
        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.textbooks.show', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/Show')
                ->has('chapter', fn ($chapterPage) => $chapterPage
                    ->where('id', $chapter->id)
                    ->where('status', TextbookChapter::STATUS_DRAFT)
                    ->where('mcq_set_code', 'C9-GP-CH08-M')
                    ->has('book')
                    ->etc()
                )
                ->has('mcqImport', fn ($import) => $import
                    ->has('prompt')
                    ->has('sample_json')
                    ->where('mcq_set_code', 'C9-GP-CH08-M')
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
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP',
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

    public function test_extraction_emails_admin_when_complete(): void
    {
        Storage::fake('public');
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->mock(\App\Services\TextbookChapterExtractionService::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andReturn([
                [
                    'id' => 'ex-1',
                    'kind' => 'example',
                    'label' => 'Example 1',
                    'source_page' => 4,
                    'question_text' => 'Find the 5th odd number.',
                    'correct_answer' => '9',
                    'answer_format' => 'integer',
                    'explanation' => 'Substitute n = 5.',
                    'needs_diagram' => false,
                    'include_in_mcq' => true,
                    'include_in_written' => true,
                    'approved' => true,
                    'mcq_options' => [],
                ],
            ]);
        });

        (new ExtractTextbookChapterJob($chapter->id))->handle(app(\App\Services\TextbookChapterExtractionService::class));

        Mail::assertSent(TextbookChapterExtracted::class, function (TextbookChapterExtracted $mail) use ($admin) {
            return $mail->hasTo($admin->email)
                && $mail->summary['items_count'] === 1;
        });
    }

    public function test_extraction_emails_admin_when_failed(): void
    {
        Mail::fake();

        [$grade, $syllabusChapter, $admin] = $this->seedClassNineChapterEight();

        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'Ganita Prakash Part I',
            'code' => 'GP',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 8,
            'title' => $syllabusChapter->name,
            'pdf_path' => 'textbooks/1/chapters/8/chapter.pdf',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $this->mock(\App\Services\TextbookChapterExtractionService::class, function ($mock): void {
            $mock->shouldReceive('extract')->once()->andThrow(new \InvalidArgumentException('AI extraction failed.'));
        });

        (new ExtractTextbookChapterJob($chapter->id))->handle(app(\App\Services\TextbookChapterExtractionService::class));

        Mail::assertSent(TextbookChapterExtractionFailed::class, fn (TextbookChapterExtractionFailed $mail) => $mail->hasTo($admin->email));
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
