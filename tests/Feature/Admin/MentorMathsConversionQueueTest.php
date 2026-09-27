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

    public function test_review_lists_sum_text_and_replaces_a_name(): void
    {
        $this->withoutVite();
        [$grade, $syllabusChapter, $admin] = $this->seedBasics();

        $book = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'MentorMaths 2',
            'code' => 'mm2',
            'practice_line' => Textbook::PRACTICE_LINE_MENTORMATHS,
            'source_ref' => 'RDS-C7',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 4,
            'title' => 'Quadratic Equations',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'question_text' => 'From RD Sharma: find x.',
                'fill_blank_question_text' => 'From RD Sharma: x is ____.',
                'fill_blank_correct_answer' => '2',
            ]],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mentormaths-conversion.review', ['grade_level_id' => $grade->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/MentorMathsReview')
                ->where('chapters.0.id', $chapter->id)
                ->where('chapters.0.sums.0.text', 'From RD Sharma: x is ____.'));

        $this->actingAs($admin)
            ->post(route('admin.mentormaths-conversion.review.replace'), [
                'find' => 'RD Sharma',
                'replace' => 'MentorMaths 2',
                'grade_level_id' => $grade->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $items = $chapter->fresh()->extraction_items;
        $this->assertSame('From MentorMaths 2: find x.', $items[0]['question_text']);
        $this->assertSame('From MentorMaths 2: x is ____.', $items[0]['fill_blank_question_text']);
    }

    public function test_chapter_page_shows_sums_and_replaces_the_selected_book_name(): void
    {
        $this->withoutVite();
        [$grade, $syllabusChapter, $admin] = $this->seedBasics();

        $book = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'name' => 'MentorMaths 2',
            'code' => 'mm2',
            'practice_line' => Textbook::PRACTICE_LINE_MENTORMATHS,
            'source_ref' => 'RSA-C7',
            'created_by' => $admin->id,
        ]);

        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 13,
            'title' => 'Statistics',
            'status' => TextbookChapter::STATUS_REVIEW,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'question_text' => 'R.S. Aggarwal: the mean is 4.',
                'fill_blank_question_text' => 'In RS Aggarwal the mean is ____.',
                'fill_blank_correct_answer' => '4',
            ]],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.mentormaths-conversion.chapter-sums', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/MentorMathsChapterSums')
                ->where('current_book_name', 'MentorMaths 2')
                ->where('old_book_name', 'RS Aggarwal'));

        $this->actingAs($admin)
            ->post(route('admin.mentormaths-conversion.replace-book', $chapter))
            ->assertRedirect()
            ->assertSessionHas('success');

        $items = $chapter->fresh()->extraction_items;
        $this->assertSame('MentorMaths 2: the mean is 4.', $items[0]['question_text']);
        $this->assertSame('In MentorMaths 2 the mean is ____.', $items[0]['fill_blank_question_text']);
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
