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
use App\Models\Worksheet;
use App\Services\MentorMathsConversionPackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentorMathsConversionPackTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_and_import_pack_merges_fill_blanks_and_publishes(): void
    {
        [$grade, $syllabusChapter, $admin] = $this->seedBasics();

        $sourceBook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'MentorMaths 2',
            'code' => 'mm2',
            'practice_line' => Textbook::PRACTICE_LINE_MENTORMATHS,
            'source_ref' => 'RDS-C7',
            'created_by' => $admin->id,
        ]);

        $items = [];
        for ($i = 1; $i <= 15; $i++) {
            $items[] = [
                'question_text' => "Source {$i}?",
                'correct_answer' => (string) $i,
                'topic' => 'Add',
                'fill_blank_question_text' => "New {$i} total is ____.",
                'fill_blank_correct_answer' => (string) ($i + 10),
                'fill_blank_answer_format' => 'integer',
                'fill_blank_explanation' => 'Answer is '.($i + 10).'.',
                'include_in_fill_blank' => true,
                'fill_blank_skipped' => false,
            ];
        }

        $sourceChapter = TextbookChapter::query()->create([
            'textbook_id' => $sourceBook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 4,
            'title' => 'Simple Equations',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => $items,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Fill',
            'set_code' => 'C7-MM2-CH04-F1',
            'status' => Worksheet::STATUS_PUBLISHED,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'created_by' => $admin->id,
        ]);
        $sourceChapter->update([
            'fill_blank_worksheet_id' => $worksheet->id,
            'fill_blank_worksheet_ids' => [$worksheet->id],
        ]);

        $pack = app(MentorMathsConversionPackService::class)->buildPack([$sourceChapter->id]);
        $this->assertSame(MentorMathsConversionPackService::FORMAT, $pack['format']);
        $this->assertCount(1, $pack['chapters']);

        // Simulate prod target: same book/chapter, source MCQs only (no fill blanks yet).
        $targetItems = [];
        for ($i = 1; $i <= 15; $i++) {
            $targetItems[] = [
                'question_text' => "Source {$i}?",
                'correct_answer' => (string) $i,
                'topic' => 'Add',
            ];
        }

        $sourceChapter->update([
            'extraction_items' => $targetItems,
            'fill_blank_worksheet_id' => null,
            'fill_blank_worksheet_ids' => null,
        ]);
        $worksheet->questions()->detach();
        $worksheet->delete();

        $result = app(MentorMathsConversionPackService::class)
            ->importPack($pack, $admin, publish: true);

        $this->assertCount(1, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['imported'][0]['published']);

        $sourceChapter->refresh();
        $this->assertNotEmpty($sourceChapter->fillBlankWorksheetIds());
        $this->assertSame(
            'New 1 total is ____.',
            $sourceChapter->extraction_items[0]['fill_blank_question_text'],
        );
    }

    /**
     * @return array{0: GradeLevel, 1: SyllabusChapter, 2: User}
     */
    private function seedBasics(): array
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
        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Simple Equations',
            'chapter_number' => 'Ch 4',
            'sort_order' => 4,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $syllabusChapter, $admin];
    }
}
