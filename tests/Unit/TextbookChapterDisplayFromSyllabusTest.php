<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextbookChapterDisplayFromSyllabusTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_label_prefers_revised_syllabus_over_stored_title(): void
    {
        $chapter = $this->seedChapter(
            storedNumber: 2,
            storedTitle: 'Fractions and Decimals',
            syllabusNumber: 'Ch 3',
            syllabusName: 'Data Handling',
        );

        $this->assertSame('Data Handling', $chapter->displayTitle());
        $this->assertSame('3', $chapter->displayChapterNumber());
        $this->assertSame('Ch 3 — Data Handling', $chapter->displaySyllabusLabel());
    }

    public function test_sync_display_from_syllabus_updates_stored_fields(): void
    {
        $chapter = $this->seedChapter(
            storedNumber: 2,
            storedTitle: 'Old title',
            syllabusNumber: 'Ch 5',
            syllabusName: 'Lines and Angles',
        );

        $this->assertTrue($chapter->syncDisplayFromSyllabus());
        $chapter->refresh();

        $this->assertSame('Lines and Angles', $chapter->title);
        $this->assertSame(5, (int) $chapter->chapter_number);
    }

    private function seedChapter(
        int $storedNumber,
        string $storedTitle,
        string $syllabusNumber,
        string $syllabusName,
    ): TextbookChapter {
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
            'name' => 'Ganita Prakash',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        return TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => $storedNumber,
            'title' => $storedTitle,
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ])->load('syllabusChapter');
    }
}
