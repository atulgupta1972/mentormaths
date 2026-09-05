<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Services\ConceptPathService;
use App\Support\ConceptPathStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConceptPathServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cursor_prompt_requires_pdf_and_mentions_teach_check(): void
    {
        $chapter = $this->seedChapter(withPdf: false);
        $service = app(ConceptPathService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->cursorPrompt($chapter);
    }

    public function test_cursor_prompt_includes_chapter_context(): void
    {
        $chapter = $this->seedChapter(withPdf: true);
        $prompt = app(ConceptPathService::class)->cursorPrompt($chapter);

        $this->assertStringContainsString('CONCEPT PATH', $prompt);
        $this->assertStringContainsString('"type": "teach"', $prompt);
        $this->assertStringContainsString('"type": "check"', $prompt);
        $this->assertStringContainsString('Algebraic Expressions', $prompt);
        $this->assertStringContainsString('Variables', $prompt);
    }

    public function test_parse_and_approve_concept_path(): void
    {
        Storage::fake('public');
        $chapter = $this->seedChapter(withPdf: true);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $service = app(ConceptPathService::class);

        $json = json_encode([
            'chapter_title' => 'Algebraic Expressions',
            'cards' => [
                [
                    'step' => 1,
                    'type' => 'teach',
                    'title' => 'Variables',
                    'body' => 'Letters stand for numbers.',
                    'example' => 'In 2a, a is a variable.',
                    'topic' => 'Variables',
                ],
                [
                    'step' => 2,
                    'type' => 'check',
                    'title' => 'Quick check',
                    'topic' => 'Variables',
                    'questions' => [
                        [
                            'question_type' => 'mcq',
                            'question' => 'Which is a variable?',
                            'options' => ['7', 'a', '12', '0'],
                            'correct_index' => 1,
                            'explanation' => 'a stands for a number.',
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $parsed = $service->parse($json);
        $this->assertSame(1, $parsed['teach_count']);
        $this->assertSame(1, $parsed['check_count']);

        $service->saveDraft($chapter, $parsed['cards'], $parsed['chapter_title']);
        $chapter->refresh();
        $this->assertSame(ConceptPathStatus::DRAFT, $chapter->concept_path_status);

        $service->approve($chapter, $admin);
        $chapter->refresh();
        $this->assertSame(ConceptPathStatus::APPROVED, $chapter->concept_path_status);
        $this->assertCount(2, $chapter->concept_path_items['cards']);
    }

    private function seedChapter(bool $withPdf): TextbookChapter
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
            'name' => 'Algebraic Expressions',
            'chapter_number' => 10,
            'sort_order' => 10,
        ]);
        SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $syllabusChapter->id,
            'name' => 'Variables',
            'learning_outcomes' => 'Understand variables and constants',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $textbook = Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'name' => 'Ganita Prakash',
            'code' => 'GP',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $pdfPath = null;
        if ($withPdf) {
            Storage::fake('public');
            $pdfPath = 'textbooks/1/chapters/10/ch.pdf';
            Storage::disk('public')->put($pdfPath, '%PDF-1.4 test');
        }

        return TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 10,
            'title' => 'Algebraic Expressions',
            'pdf_path' => $pdfPath,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
    }
}
