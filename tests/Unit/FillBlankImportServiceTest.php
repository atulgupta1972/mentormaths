<?php

namespace Tests\Unit;

use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Services\FillBlankImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillBlankImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_written_sheet_topic_prompt_asks_for_complete_sums_not_blanks(): void
    {
        [$topic] = $this->seedTopic();

        $prompt = app(FillBlankImportService::class)->writtenSheetCursorPrompt($topic, [
            'total' => 6,
            'easy' => 2,
            'medium' => 2,
            'hard' => 2,
            'sheet_kind' => 'test',
        ]);

        $this->assertStringContainsString('written chapter test', $prompt);
        $this->assertStringContainsString('COMPLETE sum', $prompt);
        $this->assertStringNotContainsString('Create fill-in-the-blank', $prompt);
        $this->assertStringNotContainsString('= ____', $prompt);
    }

    public function test_written_sheet_chapter_prompt_asks_for_complete_sums_not_blanks(): void
    {
        [$topic, $chapter] = $this->seedTopic();

        $prompt = app(FillBlankImportService::class)->cursorPromptForWrittenChapter($chapter, [[
            'topic_id' => $topic->id,
            'topic_name' => $topic->name,
            'easy' => 1,
            'medium' => 1,
            'hard' => 1,
        ]], 'test');

        $this->assertStringContainsString('written chapter test', $prompt);
        $this->assertStringContainsString('COMPLETE sum', $prompt);
        $this->assertStringNotContainsString('Create fill-in-the-blank', $prompt);
    }

    public function test_guided_fill_blank_prompt_still_uses_blanks_for_online_import(): void
    {
        [$topic] = $this->seedTopic();

        $prompt = app(FillBlankImportService::class)->cursorPrompt($topic);

        $this->assertStringContainsString('fill-in-the-blank', strtolower($prompt));
        $this->assertStringContainsString('____', $prompt);
    }

    /**
     * @return array{0: SyllabusTopic, 1: SyllabusChapter}
     */
    private function seedTopic(): array
    {
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => \App\Models\AcademicYear::query()->create([
                'name' => '2026-27',
                'starts_on' => '2026-03-01',
                'ends_on' => '2027-02-28',
                'is_active' => true,
            ])->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => \App\Models\Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths'])->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Parallel Lines',
            'chapter_number' => 'Ch 5',
            'sort_order' => 5,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Angles on parallel lines',
            'sort_order' => 1,
        ]);

        return [$topic, $chapter];
    }
}
