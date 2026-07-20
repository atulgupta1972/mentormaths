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
use App\Support\WorksheetDeliveryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
