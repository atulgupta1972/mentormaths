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
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\QuestionBankPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterMcqImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_build_chapter_cursor_prompt(): void
    {
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        $this->actingAs($admin)
            ->post(route('admin.questions.chapter-prompt'), [
                'syllabus_chapter_id' => $chapter->id,
                'plan' => [
                    [
                        'topic_id' => $topics[0]->id,
                        'topic_name' => $topics[0]->name,
                        'easy' => 2,
                        'medium' => 1,
                        'hard' => 0,
                    ],
                    [
                        'topic_id' => $topics[1]->id,
                        'topic_name' => $topics[1]->name,
                        'easy' => 1,
                        'medium' => 0,
                        'hard' => 1,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.questions.create', [
                'syllabus_chapter_id' => $chapter->id,
                'scope' => 'chapter',
            ]));

        $response = $this->actingAs($admin)
            ->get(route('admin.questions.create', [
                'syllabus_chapter_id' => $chapter->id,
                'scope' => 'chapter',
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Questions/Create')
            ->where('scope', 'chapter')
            ->where('selectedChapterId', $chapter->id)
        );
    }

    public function test_admin_can_save_chapter_mcqs_into_topic_banks(): void
    {
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        $payload = [
            'syllabus_chapter_id' => $chapter->id,
            'bank_purpose' => QuestionBankPurpose::CHAPTER_TEST,
            'rows' => [
                [
                    'syllabus_topic_id' => $topics[0]->id,
                    'topic_name' => $topics[0]->name,
                    'question_text' => 'Q1 for topic A',
                    'explanation' => 'Because A',
                    'method_hint' => 'Use rules',
                    'difficulty' => 'Easy',
                    'options' => [
                        ['option_text' => 'A', 'is_correct' => true],
                        ['option_text' => 'B', 'is_correct' => false],
                    ],
                ],
                [
                    'topic_name' => $topics[1]->name,
                    'question_text' => 'Q1 for topic B',
                    'explanation' => 'Because B',
                    'method_hint' => 'Use rules',
                    'difficulty' => 'Hard',
                    'options' => [
                        ['option_text' => 'C', 'is_correct' => true],
                        ['option_text' => 'D', 'is_correct' => false],
                    ],
                ],
            ],
        ];

        $this->actingAs($admin)
            ->post(route('admin.questions.bulk-store-chapter'), $payload)
            ->assertRedirect(route('admin.questions.chapters.show', $chapter->id));

        $this->assertSame(1, Question::query()->where('syllabus_topic_id', $topics[0]->id)->count());
        $this->assertSame(1, Question::query()->where('syllabus_topic_id', $topics[1]->id)->count());

        $this->assertSame(0, Worksheet::query()
            ->where('scope', PracticeSetScope::CHAPTER)
            ->where('syllabus_chapter_id', $chapter->id)
            ->count());
    }

    public function test_chapter_hub_shows_topic_banks_for_practice_set_questions(): void
    {
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        foreach ($topics as $index => $topic) {
            Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'question_text' => "Question {$index}",
                'type' => Question::TYPE_MCQ,
                'source' => Question::SOURCE_AI,
                'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
            ]);
        }

        $response = $this->actingAs($admin)
            ->get(route('admin.questions.chapters.show', $chapter->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('setCards', 2)
            ->where('setCards.0.type', 'bank')
            ->where('setCards.1.type', 'bank')
        );
    }

    public function test_chapter_hub_shows_one_bank_card_for_multi_topic_unpackaged_questions(): void
    {
        [$chapter, $topics, $admin] = $this->seedChapterWithTopics();

        foreach ($topics as $index => $topic) {
            Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'question_text' => "Question {$index}",
                'type' => Question::TYPE_MCQ,
                'source' => Question::SOURCE_AI,
            ]);
        }

        $response = $this->actingAs($admin)
            ->get(route('admin.questions.chapters.show', $chapter->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('setCards', 1)
            ->where('setCards.0.type', 'chapter_bank')
            ->where('setCards.0.questions_count', 2)
        );
    }

    /**
     * @return array{0: SyllabusChapter, 1: list<SyllabusTopic>, 2: User}
     */
    private function seedChapterWithTopics(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Maths']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Number Systems',
            'sort_order' => 1,
        ]);

        $topics = [
            SyllabusTopic::query()->create([
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Rational Numbers',
                'sort_order' => 1,
            ]),
            SyllabusTopic::query()->create([
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Irrational Numbers',
                'sort_order' => 2,
            ]),
        ];

        return [$chapter, $topics, $admin];
    }
}
