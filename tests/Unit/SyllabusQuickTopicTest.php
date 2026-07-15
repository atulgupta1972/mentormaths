<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Services\SyllabusImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyllabusQuickTopicTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_add_topic_to_existing_chapter(): void
    {
        $version = $this->seedSyllabusVersion();
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => '7',
            'name' => 'Percentages',
            'sort_order' => 1,
        ]);

        $service = app(SyllabusImportService::class);

        $topic = $service->addTopic($version, [
            'chapter_id' => $chapter->id,
            'chapter_number' => '7',
            'chapter_name' => 'Percentages',
            'chapter_head_id' => null,
        ], [
            'topic_name' => 'Advanced Percent Problems',
            'learning_outcomes' => 'Complex percentage adjustments',
            'difficulty' => 'Easy',
            'planned_periods' => 3,
            'remarks' => null,
        ]);

        $this->assertSame('Advanced Percent Problems', $topic->name);
        $this->assertSame($chapter->id, $topic->syllabus_chapter_id);
        $this->assertSame(1, $chapter->topics()->count());
    }

    public function test_can_add_topic_with_new_chapter(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $topic = $service->addTopic($version, [
            'chapter_id' => null,
            'chapter_number' => '8',
            'chapter_name' => 'Algebra',
            'chapter_head_id' => null,
        ], [
            'topic_name' => 'Linear Equations',
            'learning_outcomes' => null,
            'difficulty' => null,
            'planned_periods' => null,
            'remarks' => null,
        ]);

        $this->assertSame('Linear Equations', $topic->name);
        $this->assertSame('Algebra', $topic->chapter->name);
        $this->assertSame(1, $version->fresh()->chapters()->count());
    }

    private function seedSyllabusVersion(): SyllabusVersion
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

        return SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
            'status' => SyllabusVersion::STATUS_DRAFT,
        ]);
    }
}
