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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentorMathsConversionQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_lists_publisher_chapters_and_hides_completed(): void
    {
        $this->withoutVite();
        [$grade, $syllabusChapter, $admin] = $this->seedBasics();

        $rds = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'RD Sharma',
            'code' => 'rds',
            'practice_line' => Textbook::PRACTICE_LINE_STANDARD,
            'created_by' => $admin->id,
        ]);

        $pending = TextbookChapter::query()->create([
            'textbook_id' => $rds->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 1,
            'title' => 'Integers',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [['question_text' => 'Q?', 'correct_answer' => '1']],
        ]);

        $doneBook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'MentorMaths 1',
            'code' => 'mm1',
            'practice_line' => Textbook::PRACTICE_LINE_MENTORMATHS,
            'source_ref' => 'RDS-C7',
            'created_by' => $admin->id,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Fill',
            'set_code' => 'C7-MM1-CH02-F1',
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        $doneChapter = TextbookChapter::query()->create([
            'textbook_id' => $doneBook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Fractions',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'fill_blank_worksheet_id' => $worksheet->id,
            'fill_blank_worksheet_ids' => [$worksheet->id],
            'extraction_items' => [['question_text' => 'Q?', 'correct_answer' => '1']],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mentormaths-conversion.index', ['grade_level_id' => $grade->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/MentorMathsQueue')
                ->where('pending_count', 1)
                ->has('chapters', 1)
                ->where('chapters.0.id', $pending->id)
                ->where('done_count', 1)
                ->has('done_chapters', 1)
                ->where('done_chapters.0.id', $doneChapter->id)
                ->where('done_chapters.0.has_fill_blank_published', true)
            );
    }

    public function test_rebrand_moves_book_to_mentormaths_line(): void
    {
        $this->withoutVite();
        [$grade, $syllabusChapter, $admin] = $this->seedBasics();

        $rds = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'RD Sharma',
            'code' => 'rds',
            'practice_line' => Textbook::PRACTICE_LINE_STANDARD,
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $rds->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 3,
            'title' => 'Data',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [['question_text' => 'Total?', 'correct_answer' => '10']],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.mentormaths-conversion.rebrand', $chapter), [
                'book_name' => 'MentorMaths 1',
                'book_code' => 'mm1',
                'source_ref' => 'RDS-C7',
            ])
            ->assertRedirect(route('admin.mentormaths-conversion.show', $chapter));

        $rds->refresh();
        $this->assertTrue($rds->isMentorMathsPracticeLine());
        $this->assertSame('MentorMaths 1', $rds->name);
        $this->assertSame('mm1', $rds->code);
        $this->assertSame('RDS-C7', $rds->source_ref);
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
            'name' => 'Integers',
            'chapter_number' => 'Ch 1',
            'sort_order' => 1,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $syllabusChapter, $admin];
    }
}
