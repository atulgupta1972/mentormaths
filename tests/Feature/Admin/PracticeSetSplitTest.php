<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\PracticeSetSplitService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PracticeSetSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_split_large_chapter_set_into_parts_of_twenty(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$worksheet] = $this->seedChapterSetWithQuestions(45, 'C9-IEMH1-CH02-M');

        $this->actingAs($admin)
            ->post(route('admin.practice-sets.split', $worksheet), ['batch_size' => 20])
            ->assertRedirect(route('admin.questions.sets.show', $worksheet));

        $worksheet->refresh();
        $this->assertSame('C9-IEMH1-CH02-M1', $worksheet->set_code);
        $this->assertSame(20, $worksheet->questions()->count());

        $part2 = Worksheet::query()->where('set_code', 'C9-IEMH1-CH02-M2')->first();
        $part3 = Worksheet::query()->where('set_code', 'C9-IEMH1-CH02-M3')->first();

        $this->assertNotNull($part2);
        $this->assertNotNull($part3);
        $this->assertSame(20, $part2->questions()->count());
        $this->assertSame(5, $part3->questions()->count());
    }

    public function test_split_updates_textbook_chapter_mcq_worksheet_ids(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$worksheet, $textbookChapter] = $this->seedChapterSetWithQuestions(25, 'C7-GP-CH01-M', true);

        $this->actingAs($admin)
            ->post(route('admin.practice-sets.split', $worksheet), ['batch_size' => 20])
            ->assertRedirect();

        $textbookChapter->refresh();
        $ids = $textbookChapter->mcqWorksheetIds();
        $this->assertCount(2, $ids);
        $this->assertSame($worksheet->id, $ids[0]);
        $this->assertNotNull(Worksheet::query()->find($ids[1]));
    }

    public function test_split_plan_helper_matches_batch_size(): void
    {
        $plan = app(PracticeSetSplitService::class)->buildPlan(87, 'C9-IEMH1-CH02-M', 20);

        $this->assertCount(5, $plan);
        $this->assertSame('C9-IEMH1-CH02-M1', $plan[0]['set_code']);
        $this->assertSame(20, $plan[0]['count']);
        $this->assertSame('C9-IEMH1-CH02-M5', $plan[4]['set_code']);
        $this->assertSame(7, $plan[4]['count']);
    }

    public function test_oversized_index_lists_sets_above_threshold_descending(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$large] = $this->seedChapterSetWithQuestions(40, 'C9-BIG-CH01-M');

        $this->actingAs($admin)
            ->get(route('admin.practice-sets.oversized', ['min_questions' => 30]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/PracticeSets/Oversized')
                ->has('sets', 1)
                ->where('sets.0.id', $large->id)
                ->where('sets.0.questions_count', 40)
                ->where('filters.min_questions', 30));
    }

    public function test_oversized_page_can_split_set_in_half(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$worksheet] = $this->seedChapterSetWithQuestions(40, 'C9-HALF-CH01-M');

        $this->actingAs($admin)
            ->post(route('admin.practice-sets.oversized.split', $worksheet), [
                'mode' => 'half',
                'min_questions' => 30,
            ])
            ->assertRedirect(route('admin.practice-sets.oversized', [
                'min_questions' => 30,
                'kind' => 'all',
            ]));

        $worksheet->refresh();
        $this->assertSame(20, $worksheet->questions()->count());
        $this->assertSame('C9-HALF-CH01-M1', $worksheet->set_code);
        $this->assertSame(20, Worksheet::query()->where('set_code', 'C9-HALF-CH01-M2')->first()?->questions()->count());
    }

    public function test_admin_can_rename_set_code_in_place(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$worksheet] = $this->seedChapterSetWithQuestions(5, 'C8-GP-CH02-M1');

        $this->actingAs($admin)
            ->patch(route('admin.practice-sets.update-set-code', $worksheet), [
                'set_code' => 'C8-GP-CH02-M1-OLD',
                'stay' => true,
            ])
            ->assertRedirect();

        $this->assertSame('C8-GP-CH02-M1-OLD', $worksheet->fresh()->set_code);
    }

    public function test_split_blocked_when_target_codes_exist_and_related_sets_are_exposed(): void
    {
        $this->withoutVite();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$large] = $this->seedChapterSetWithQuestions(40, 'C8-GP-CH02-M');

        $conflict = Worksheet::query()->create([
            'title' => 'Existing part',
            'set_number' => 2,
            'set_code' => 'C8-GP-CH02-M1',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $large->syllabus_chapter_id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $large->created_by,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.practice-sets.split', $large), ['batch_size' => 20])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->get(route('admin.questions.sets.show', $large))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/SetQuestions')
                ->where('splitRelatedSets.0.id', $conflict->id)
                ->where('splitRelatedSets.0.set_code', 'C8-GP-CH02-M1'));
    }

    /**
     * @return array{0: Worksheet, 1: ?TextbookChapter}
     */
    private function seedChapterSetWithQuestions(int $count, string $setCode, bool $withTextbook = false): array
    {
        $year = AcademicYear::query()->firstOrCreate(
            ['name' => '2026-27'],
            [
                'starts_on' => '2026-04-01',
                'ends_on' => '2027-03-31',
                'is_active' => true,
            ],
        );
        $board = Board::query()->firstOrCreate(
            ['code' => 'CBSE'],
            ['name' => 'CBSE', 'is_active' => true],
        );
        $grade = GradeLevel::query()->firstOrCreate(
            ['name' => 'Class 9'],
            [
                'sort_order' => 9,
                'is_active' => true,
            ],
        );
        $subject = Subject::query()->firstOrCreate(
            ['code' => 'MATHS'],
            ['name' => 'Mathematics', 'is_active' => true],
        );
        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 9 Maths',
            'is_active' => true,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => 2,
            'name' => 'Polynomials',
            'sort_order' => 2,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'topic_number' => 1,
            'name' => 'Intro',
            'sort_order' => 1,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Chapter MCQ',
            'set_number' => 1,
            'set_code' => $setCode,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => User::factory()->create(['role' => User::ROLE_ADMIN])->id,
        ]);

        for ($i = 1; $i <= $count; $i++) {
            $question = Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'question_text' => "Q{$i} secret {$i}",
                'type' => Question::TYPE_MCQ,
                'difficulty' => 'easy',
                'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
                'created_by' => $worksheet->created_by,
            ]);
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'option_text' => 'A',
                'is_correct' => true,
                'sort_order' => 1,
            ]);
            $worksheet->questions()->attach($question->id, ['sort_order' => $i]);
        }

        $textbookChapter = null;
        if ($withTextbook) {
            $textbook = Textbook::query()->create([
                'name' => 'Ganita Prakash',
                'code' => 'GP',
                'grade_level_id' => $grade->id,
                'is_active' => true,
                'created_by' => $worksheet->created_by,
            ]);
            $textbookChapter = TextbookChapter::query()->create([
                'textbook_id' => $textbook->id,
                'syllabus_chapter_id' => $chapter->id,
                'chapter_number' => 1,
                'title' => 'Ch 1',
                'status' => TextbookChapter::STATUS_PUBLISHED,
                'mcq_worksheet_id' => $worksheet->id,
                'mcq_worksheet_ids' => [$worksheet->id],
                'created_by' => $worksheet->created_by,
            ]);
        }

        return [$worksheet, $textbookChapter];
    }
}
