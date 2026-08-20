<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\PracticeSetTierClassifier;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeSetTierClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_by_majority_difficulty(): void
    {
        [$worksheet] = $this->seedWorksheetWithDifficulties([
            'Easy' => 2,
            'Medium' => 5,
            'Hard' => 1,
        ]);

        $tier = app(PracticeSetTierClassifier::class)->classifyWorksheet($worksheet->fresh('questions'));

        $this->assertSame(PracticeSetTier::BUILDER, $tier);
    }

    public function test_classify_all_updates_tier(): void
    {
        [$worksheet] = $this->seedWorksheetWithDifficulties([
            'Hard' => 4,
        ], setCode: 'S902');

        $stats = app(PracticeSetTierClassifier::class)->classifyAll(dryRun: false);

        $this->assertSame(1, $stats['updated']);
        $this->assertSame(PracticeSetTier::CHAMPION, $worksheet->fresh()->tier);
    }

    public function test_skips_chapter_test(): void
    {
        [$worksheet] = $this->seedWorksheetWithDifficulties(
            ['Hard' => 1],
            setCode: 'T901',
            tier: PracticeSetTier::CHAPTER_TEST,
            scope: PracticeSetScope::CHAPTER,
        );

        $stats = app(PracticeSetTierClassifier::class)->classifyAll(dryRun: false);

        $this->assertSame(0, $stats['updated']);
        $this->assertSame(PracticeSetTier::CHAPTER_TEST, $worksheet->fresh()->tier);
    }

    /**
     * @param  array<string, int>  $difficultyCounts
     * @return array{0: Worksheet, 1: SyllabusTopic}
     */
    private function seedWorksheetWithDifficulties(
        array $difficultyCounts,
        string $setCode = 'S901',
        string $tier = PracticeSetTier::STARTER,
        string $scope = PracticeSetScope::TOPIC,
    ): array {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_current' => true,
        ]);
        $board = Board::query()->create(['name' => 'CBSE', 'code' => 'CBSE']);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7]);
        $subject = Subject::query()->create(['name' => 'Maths', 'code' => 'MATHS']);
        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'subject_id' => $subject->id,
            'name' => 'Test syllabus',
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => '1',
            'name' => 'Integers',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Set '.$setCode,
            'set_number' => 1,
            'set_code' => $setCode,
            'tier' => $tier,
            'scope' => $scope,
            'syllabus_topic_id' => $scope === PracticeSetScope::TOPIC ? $topic->id : null,
            'syllabus_chapter_id' => $scope === PracticeSetScope::CHAPTER ? $chapter->id : null,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
        ]);

        $sort = 1;
        foreach ($difficultyCounts as $difficulty => $count) {
            for ($i = 0; $i < $count; $i++) {
                $question = Question::query()->create([
                    'syllabus_topic_id' => $topic->id,
                    'type' => Question::TYPE_MCQ,
                    'question_text' => "{$difficulty} {$i}",
                    'difficulty' => $difficulty,
                    'source' => Question::SOURCE_MANUAL,
                ]);
                $worksheet->questions()->attach($question->id, ['sort_order' => $sort++]);
            }
        }

        return [$worksheet, $topic];
    }
}
