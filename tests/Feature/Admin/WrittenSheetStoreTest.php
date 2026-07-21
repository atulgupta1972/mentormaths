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
use App\Services\WrittenSheetPdfImportService;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WrittenSheetStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_written_sheet_from_manual_chapter_rows(): void
    {
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        $response = $this->actingAs($admin)->post(route('admin.written-sheets.store'), [
            'source_mode' => 'manual',
            'sheet_kind' => 'practice',
            'chapter_id' => $chapter->id,
            'topic_scope' => 'multiple',
            'topic_id' => '',
            'topic_ids' => [$topics[0]->id, $topics[1]->id],
            'manual_questions' => [
                [
                    'question_text' => 'What is 1/2 + 1/4?',
                    'correct_answer' => '3/4',
                    'answer_format' => 'fraction',
                    'topic_name' => $topics[0]->name,
                    'syllabus_topic_id' => '',
                    'method_hint' => '',
                    'explanation' => '',
                ],
                [
                    'question_text' => 'What is 0.5 × 2?',
                    'correct_answer' => '1',
                    'answer_format' => 'integer',
                    'topic_name' => $topics[1]->name,
                    'syllabus_topic_id' => '',
                    'method_hint' => '',
                    'explanation' => '',
                ],
            ],
            'chapter_plan' => [
                [
                    'topic_id' => $topics[0]->id,
                    'topic_name' => $topics[0]->name,
                    'easy' => 1,
                    'medium' => 1,
                    'hard' => 0,
                    'sort_order' => 1,
                ],
                [
                    'topic_id' => $topics[1]->id,
                    'topic_name' => $topics[1]->name,
                    'easy' => 1,
                    'medium' => 0,
                    'hard' => 0,
                    'sort_order' => 2,
                ],
            ],
            'notes' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $worksheet = Worksheet::query()->where('delivery_mode', WorksheetDeliveryMode::WRITTEN)->first();
        $this->assertNotNull($worksheet);
        $this->assertNotNull($worksheet->written_pdf_path);
    }

    public function test_manual_store_ignores_empty_question_ids(): void
    {
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        $response = $this->actingAs($admin)->post(route('admin.written-sheets.store'), [
            'source_mode' => 'manual',
            'sheet_kind' => 'practice',
            'chapter_id' => $chapter->id,
            'topic_scope' => 'one',
            'topic_id' => $topics[0]->id,
            'question_ids' => [],
            'manual_questions' => [
                [
                    'question_text' => 'What is 1/2 + 1/4?',
                    'correct_answer' => '3/4',
                    'answer_format' => 'fraction',
                    'topic_name' => $topics[0]->name,
                    'syllabus_topic_id' => '',
                    'method_hint' => '',
                    'explanation' => '',
                ],
            ],
            'notes' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_manual_store_sanitizes_invalid_topic_ids_and_answer_formats(): void
    {
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        $response = $this->actingAs($admin)->post(route('admin.written-sheets.store'), [
            'source_mode' => 'manual',
            'sheet_kind' => 'practice',
            'chapter_id' => $chapter->id,
            'topic_scope' => 'multiple',
            'topic_id' => '',
            'topic_ids' => [$topics[0]->id, $topics[1]->id],
            'manual_questions' => [
                [
                    'question_text' => 'What is 1/2 + 1/4?',
                    'correct_answer' => '3/4',
                    'answer_format' => 'number',
                    'topic_name' => $topics[0]->name,
                    'syllabus_topic_id' => 99999,
                    'method_hint' => '',
                    'explanation' => '',
                ],
            ],
            'chapter_plan' => [
                [
                    'topic_id' => $topics[0]->id,
                    'topic_name' => $topics[0]->name,
                    'easy' => 1,
                    'medium' => 0,
                    'hard' => 0,
                    'sort_order' => 1,
                ],
            ],
            'notes' => '',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_admin_can_create_written_sheet_from_uploaded_pdf_and_answer_key(): void
    {
        Storage::fake('public');
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        $token = '550e8400-e29b-41d4-a716-446655440000';
        $pdfPath = "temp/pdf-imports/written-sheet-pdf/{$token}/source.pdf";
        Storage::disk('public')->put($pdfPath, '%PDF-1.4 fake worksheet');

        Cache::put("written_sheet_pdf:{$token}", [
            'token' => $token,
            'pdf_path' => $pdfPath,
            'original_name' => 'chapter-5.pdf',
        ], now()->addHour());

        $response = $this->actingAs($admin)->post(route('admin.written-sheets.store'), [
            'source_mode' => 'pdf',
            'pdf_import_token' => $token,
            'sheet_kind' => 'practice',
            'chapter_id' => $chapter->id,
            'topic_scope' => 'one',
            'topic_id' => $topics[0]->id,
            'answer_key' => [
                [
                    'correct_answer' => '42',
                    'answer_format' => 'integer',
                    'method_hint' => 'Add first',
                ],
                [
                    'correct_answer' => '3/4',
                    'answer_format' => 'fraction',
                ],
            ],
            'notes' => 'Uploaded chapter PDF',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $worksheet = Worksheet::query()->where('delivery_mode', WorksheetDeliveryMode::WRITTEN)->first();
        $this->assertNotNull($worksheet);
        $this->assertSame(WrittenSheetStatus::PENDING_REVIEW, $worksheet->written_status);
        $this->assertNotNull($worksheet->written_pdf_path);
        $this->assertTrue(Storage::disk('public')->exists($worksheet->written_pdf_path));
        $this->assertSame(2, $worksheet->questions()->count());
        $this->assertSame('Q1 — see worksheet PDF', $worksheet->questions()->orderBy('worksheet_question.sort_order')->first()->question_text);
        $this->assertNull(Cache::get("written_sheet_pdf:{$token}"));
    }

    public function test_admin_can_stage_written_sheet_pdf(): void
    {
        Storage::fake('public');
        [, , $admin] = $this->seedChapterWithTopics();

        $response = $this->actingAs($admin)->postJson(route('admin.written-sheets.stage-pdf'), [
            'pdf' => UploadedFile::fake()->create('worksheet.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'pdf_url']);
        $this->assertNotNull(Cache::get('written_sheet_pdf:'.$response->json('token')));
    }

    public function test_admin_can_parse_answer_sheet_pdf(): void
    {
        [, , $admin] = $this->seedChapterWithTopics();

        $this->mock(WrittenSheetPdfImportService::class, function ($mock): void {
            $mock->shouldReceive('parseAnswerSheet')
                ->once()
                ->andReturn([
                    'answer_key' => [
                        ['correct_answer' => '42', 'answer_format' => 'integer', 'method_hint' => null],
                        ['correct_answer' => '3/4', 'answer_format' => 'fraction', 'method_hint' => null],
                    ],
                    'parsed_count' => 2,
                    'warnings' => [],
                    'extracted_preview' => '1. 42',
                ]);
        });

        $response = $this->actingAs($admin)->postJson(route('admin.written-sheets.parse-answer-pdf'), [
            'pdf' => UploadedFile::fake()->create('answers.pdf', 100, 'application/pdf'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('parsed_count', 2);
        $response->assertJsonPath('answer_key.0.correct_answer', '42');
    }

    /**
     * @return array{0: SyllabusChapter, 1: list<SyllabusTopic>, 2: User}
     */
    private function seedChapterWithTopics(): array
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

        $topics = [
            SyllabusTopic::query()->create([
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Multiplication of Fractions',
                'sort_order' => 1,
            ]),
            SyllabusTopic::query()->create([
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Division of Fractions',
                'sort_order' => 2,
            ]),
        ];

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$chapter, $topics, $admin];
    }
}
