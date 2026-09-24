<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\PracticeSetSplitService;
use App\Services\WrittenSheetPdfService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use App\Support\WorksheetPurpose;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrittenSheetSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_parse_sizes_and_build_plan_for_twelve_plus_eight(): void
    {
        $svc = app(PracticeSetSplitService::class);

        $this->assertSame([12, 8], $svc->parseSizesExpression('12+8'));
        $this->assertSame([10, 10], $svc->parseSizesExpression('10 + 10'));

        $plan = $svc->buildPlanFromSizes(20, 'C7-MM2-CH01-W', [12, 8]);

        $this->assertCount(2, $plan);
        $this->assertSame(12, $plan[0]['count']);
        $this->assertSame(8, $plan[1]['count']);
        $this->assertSame('C7-MM2-CH01-W1', $plan[0]['set_code']);
        $this->assertSame('C7-MM2-CH01-W2', $plan[1]['set_code']);
    }

    public function test_admin_can_split_written_sheet_into_custom_parts(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $worksheet = $this->seedWrittenSheet(20, 'C7-GT-CH01-W');

        $this->mock(WrittenSheetPdfService::class, function ($mock) {
            $mock->shouldReceive('generate')->andReturnUsing(function (Worksheet $sheet) {
                return 'written-sheets/'.$sheet->id.'/test.pdf';
            });
        });

        $this->actingAs($admin)
            ->post(route('admin.written-sheets.split', $worksheet), ['sizes' => '12+8'])
            ->assertRedirect(route('admin.written-sheets.show', $worksheet));

        $worksheet->refresh();
        $this->assertSame('C7-GT-CH01-W1', $worksheet->set_code);
        $this->assertSame(12, $worksheet->questions()->count());
        $this->assertSame(WorksheetDeliveryMode::WRITTEN, $worksheet->delivery_mode);
        $this->assertSame(WrittenSheetStatus::PENDING_REVIEW, $worksheet->written_status);

        $part2 = Worksheet::query()->where('set_code', 'C7-GT-CH01-W2')->first();
        $this->assertNotNull($part2);
        $this->assertSame(8, $part2->questions()->count());
        $this->assertSame(WorksheetDeliveryMode::WRITTEN, $part2->delivery_mode);
        $this->assertSame(WrittenSheetStatus::PENDING_REVIEW, $part2->written_status);
        $this->assertNotNull($part2->written_pdf_path);
    }

    public function test_written_split_rejects_sizes_that_do_not_add_up(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $worksheet = $this->seedWrittenSheet(20, 'C7-GT-CH02-W');

        $this->actingAs($admin)
            ->post(route('admin.written-sheets.split', $worksheet), ['sizes' => '10+5'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(20, $worksheet->fresh()->questions()->count());
    }

    private function seedWrittenSheet(int $count, string $setCode): Worksheet
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 7 Maths',
            'is_active' => true,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => 1,
            'name' => 'Geometric Twins',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Criteria for Congruence (SSS, SAS)',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Geometric Twins written',
            'set_number' => 1,
            'set_code' => $setCode,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => WrittenSheetStatus::PENDING_REVIEW,
            'written_pdf_path' => 'written-sheets/old.pdf',
            'created_by' => $admin->id,
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $question = Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'type' => Question::TYPE_FILL_IN_BLANK,
                'question_text' => "Written sum {$i}. Find the measure.",
                'difficulty' => 'Medium',
                'source' => Question::SOURCE_AI,
                'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
                'created_by' => $admin->id,
            ]);
            QuestionBlankAnswer::query()->create([
                'question_id' => $question->id,
                'answer_format' => QuestionBlankAnswer::FORMAT_INTEGER,
                'correct_answer' => (string) $i,
            ]);
            $worksheet->questions()->attach($question->id, ['sort_order' => $i]);
        }

        return $worksheet->fresh()->loadCount('questions');
    }
}
