<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Services\FillBlankImportService;
use App\Support\TopicName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_matches_latex_and_unicode_topic_labels(): void
    {
        $latex = 'relationship between sides ($a^2 + b^2 = c^2$),';
        $unicode = 'relationship between sides (a² + b² = c²)';

        $this->assertSame(
            TopicName::normalize($latex),
            TopicName::normalize($unicode),
        );
        $this->assertSame(
            'relationship between sides (a² + b² = c²)',
            TopicName::display($latex),
        );
    }

    public function test_fill_blank_import_resolves_unicode_topic_to_latex_syllabus_name(): void
    {
        $chapter = $this->seedChapterWithTopic('relationship between sides ($a^2 + b^2 = c^2$),');

        $id = app(FillBlankImportService::class)->resolveTopicIdForChapterRow($chapter, [
            'topic' => 'relationship between sides (a² + b² = c²)',
        ]);

        $this->assertSame($chapter->topics->first()->id, $id);
    }

    private function seedChapterWithTopic(string $topicName): SyllabusChapter
    {
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => AcademicYear::query()->create([
                'name' => '2026-27',
                'starts_on' => '2026-03-01',
                'ends_on' => '2027-02-28',
                'is_active' => true,
            ])->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths'])->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'chapter_number' => 2,
            'name' => 'The Baudhayana-Pythagoras Theorem',
            'sort_order' => 2,
        ]);
        SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => $topicName,
            'sort_order' => 1,
        ]);

        return $chapter->fresh('topics');
    }
}
