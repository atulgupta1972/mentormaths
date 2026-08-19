<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionHubTopicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_chapter_topics_hub(): void
    {
        [$chapter, $admin] = $this->seedClassSevenChapter();

        $this->actingAs($admin)
            ->get(route('admin.questions.chapters.show', $chapter->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Questions/Hub/Topics')
                ->where('chapter.id', $chapter->id)
                ->has('stats')
            );
    }

    public function test_chapter_hub_shows_book_content_convert_links(): void
    {
        [$chapter, $admin] = $this->seedClassSevenChapter();

        $book = Textbook::query()->create([
            'grade_level_id' => $chapter->syllabusVersion->grade_level_id,
            'name' => 'Maths Mate',
            'code' => 'MM',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $textbookChapter = TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $chapter->id,
            'chapter_number' => 1,
            'title' => 'We the Travellers',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
            'extraction_items' => [[
                'question_text' => 'What is 2 + 2?',
                'correct_answer' => '4',
            ]],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.questions.chapters.show', $chapter->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('bookContent', 1)
                ->where('bookContent.0.book_code', 'MM')
                ->where('bookContent.0.can_convert', true)
                ->where('bookContent.0.convert_url', fn ($url) => str_contains((string) $url, (string) $textbookChapter->id)
                    && str_contains((string) $url, '#convert')));
    }

    public function test_chapter_hub_skips_bank_cards_when_grade_context_is_missing(): void
    {
        [$chapter, $admin, $topic] = $this->seedClassSevenChapter(withTopic: true);

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Unpackaged chapter test question',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_AI,
            'bank_purpose' => QuestionBankPurpose::CHAPTER_TEST,
        ]);

        $chapter->load('syllabusVersion.gradeLevel');
        $chapter->syllabusVersion->setRelation('gradeLevel', null);

        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\PracticeSetCodeService::class)->generateChapterTest($chapter);
    }

    public function test_chapter_hub_shows_bank_card_for_unpackaged_chapter_test_questions(): void
    {
        [$chapter, $admin, $topic] = $this->seedClassSevenChapter(withTopic: true);

        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Unpackaged chapter test question',
            'type' => Question::TYPE_MCQ,
            'source' => Question::SOURCE_AI,
            'bank_purpose' => QuestionBankPurpose::CHAPTER_TEST,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.questions.chapters.show', $chapter->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('setCards', 1)
                ->where('setCards.0.type', 'chapter_bank')
            );
    }

    public function test_chapter_hub_lists_written_sheets_when_present(): void
    {
        [$chapter, $admin, $topic] = $this->seedClassSevenChapter(withTopic: true);

        Worksheet::query()->create([
            'title' => 'Written practice',
            'set_number' => 1,
            'set_code' => 'C7-GP-CH02-W1',
            'tier' => 'starter',
            'scope' => 'topic',
            'syllabus_topic_id' => $topic->id,
            'status' => 'draft',
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.questions.chapters.show', $chapter->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('writtenSheets', 1)
                ->where('writtenSheets.0.set_code', 'C7-GP-CH02-W1')
            );
    }

    /**
     * @return array{0: SyllabusChapter, 1: User, 2?: SyllabusTopic, 3?: GradeLevel}
     */
    private function seedClassSevenChapter(bool $withTopic = false): array
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

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Fractions and Decimals',
            'chapter_number' => 2,
            'sort_order' => 2,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        if (! $withTopic) {
            return [$chapter, $admin];
        }

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Multiplication of Fractions',
            'sort_order' => 1,
        ]);

        return [$chapter, $admin, $topic, $grade];
    }
}
