<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\TextbookChapterMcqImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TextbookChapterMixedPublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_import_publish_creates_fill_blank_and_mcq_in_one_set(): void
    {
        Storage::fake('public');

        [$grade, $syllabusChapter, $admin] = $this->seedClassSevenChapterOne();

        $json = json_encode([
            'questions' => [
                [
                    'topic' => 'Sum',
                    'question' => 'What is 10 + 5?',
                    'options' => ['12', '14', '15', '16', '17', '18', '19', '20'],
                    'correct_index' => 2,
                    'explanation' => '10 + 5 = 15',
                ],
                [
                    'topic' => 'Names',
                    'question' => 'Who scored highest?',
                    'options' => ['Ali', 'Bo', 'Cy', 'Di', 'Ed', 'Fa', 'Gi', 'Hu'],
                    'correct_index' => 0,
                    'explanation' => 'Ali had the highest score',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => Textbook::query()->create([
                'grade_level_id' => $grade->id,
                'name' => 'NCERT',
                'code' => 'NCERT',
                'created_by' => $admin->id,
            ])->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Integers',
            'pdf_path' => 'textbooks/1/chapters/1/chapter.pdf',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
        ]);

        $chapter = app(TextbookChapterMcqImportService::class)->import($textbookChapter, $json);
        $items = $chapter->extraction_items ?? [];

        $this->assertSame('fill_blank', $items[0]['question_type']);
        $this->assertSame('mcq', $items[1]['question_type']);
        $this->assertCount(8, $items[1]['mcq_options']);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.publish', $textbookChapter), [
                'items' => $items,
                'mcq_set_plan' => [[
                    'set_code' => 'C7-NCERT-CH01-M',
                    'q_from' => 1,
                    'q_to' => 2,
                    'description' => '',
                ]],
            ])
            ->assertRedirect();

        $worksheet = Worksheet::query()->findOrFail($textbookChapter->fresh()->mcq_worksheet_id);
        $types = $worksheet->questions()->orderByPivot('sort_order')->pluck('type')->all();

        $this->assertSame([
            Question::TYPE_FILL_IN_BLANK,
            Question::TYPE_MCQ,
        ], $types);

        $mcq = $worksheet->questions()->where('type', Question::TYPE_MCQ)->first();
        $this->assertSame(8, $mcq->options()->count());
    }

    /**
     * @return array{0: GradeLevel, 1: SyllabusChapter, 2: User}
     */
    private function seedClassSevenChapterOne(): array
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
            'chapter_number' => 'Ch 1',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $chapter, $admin];
    }
}
