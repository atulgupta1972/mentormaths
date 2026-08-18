<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\ChapterHead;
use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
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

    public function test_sync_rows_treats_empty_chapter_head_id_as_null(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'Shapes Around Us',
            'chapter_head_id' => '',
            'topic_name' => 'Geometry & Spatial Sense',
            'learning_outcomes' => '2D/3D shapes, spatial perception',
            'difficulty' => 'Easy',
            'planned_periods' => '8–10',
            'remarks' => 'Activity-based introduction to geometry',
        ]]);

        $chapter = $version->fresh()->chapters()->first();
        $topic = $chapter?->topics()->first();

        $this->assertNotNull($chapter);
        $this->assertNull($chapter->chapter_head_id);
        $this->assertSame('Shapes Around Us', $chapter->name);
        $this->assertSame('Geometry & Spatial Sense', $topic?->name);
        $this->assertSame(8, $topic?->planned_periods);
    }

    public function test_excel_with_ncert_chapter_column_maps_correctly(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'Geometry',
            'topic_name' => '2D and 3D Shapes',
            'learning_outcomes' => 'Identifying flat and solid shapes',
            'remarks' => 'NCERT: Shapes Around Us',
        ]]);

        $topic = $version->fresh()->chapters()->first()?->topics()->first();

        $this->assertSame('1', $version->fresh()->chapters()->first()?->chapter_number);
        $this->assertSame('Geometry', $version->fresh()->chapters()->first()?->name);
        $this->assertSame('NCERT: Shapes Around Us', $topic?->remarks);
    }

    public function test_clear_all_rows_removes_chapters_and_topics(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'Geometry',
            'topic_name' => '2D and 3D Shapes',
            'learning_outcomes' => null,
            'remarks' => null,
        ]]);

        $this->assertSame(1, $version->fresh()->chapters()->count());

        $service->clearAllRows($version);

        $version = $version->fresh();
        $this->assertSame(0, $version->chapters()->count());
        $this->assertSame(SyllabusVersion::STATUS_DRAFT, $version->status);
    }

    public function test_omitting_chapter_with_textbook_mcqs_keeps_the_bank(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $keptChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => '4',
            'name' => 'Algebra',
            'sort_order' => 1,
        ]);
        $droppable = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => '5',
            'name' => 'Empty chapter',
            'sort_order' => 2,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $keptChapter->id,
            'name' => 'Textbook',
            'sort_order' => 900,
        ]);
        SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $droppable->id,
            'name' => 'Old topic',
            'sort_order' => 1,
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $book = Textbook::query()->create([
            'grade_level_id' => $version->grade_level_id,
            'name' => 'NCERT Maths',
            'code' => 'ncert',
            'created_by' => $admin->id,
        ]);
        TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $keptChapter->id,
            'chapter_number' => 4,
            'title' => 'Algebra',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);
        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'question_text' => 'What is (a+b)^2?',
            'source' => Question::SOURCE_PDF,
        ]);

        $result = $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'New heading',
            'topic_name' => 'New topic',
        ]]);

        $this->assertTrue($keptChapter->exists());
        $this->assertDatabaseHas('syllabus_chapters', ['id' => $keptChapter->id, 'name' => 'Algebra']);
        $this->assertDatabaseMissing('syllabus_chapters', ['id' => $droppable->id]);
        $this->assertDatabaseHas('questions', ['id' => $question->id, 'syllabus_topic_id' => $topic->id]);
        $this->assertDatabaseHas('textbook_chapters', ['syllabus_chapter_id' => $keptChapter->id]);
        $this->assertNotEmpty($result['kept_content_chapters']);
    }

    public function test_clear_all_keeps_chapters_with_uploaded_content(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $keptChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => '4',
            'name' => 'Algebra',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $keptChapter->id,
            'name' => 'Textbook',
            'sort_order' => 900,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $book = Textbook::query()->create([
            'grade_level_id' => $version->grade_level_id,
            'name' => 'NCERT Maths',
            'code' => 'ncert',
            'created_by' => $admin->id,
        ]);
        TextbookChapter::query()->create([
            'textbook_id' => $book->id,
            'syllabus_chapter_id' => $keptChapter->id,
            'chapter_number' => 4,
            'title' => 'Algebra',
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);
        Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'question_text' => 'Keep me',
            'source' => Question::SOURCE_PDF,
        ]);

        $result = $service->clearAllRows($version);

        $this->assertSame(1, $version->fresh()->chapters()->count());
        $this->assertDatabaseHas('questions', ['question_text' => 'Keep me']);
        $this->assertNotEmpty($result['kept_content_chapters']);
    }

    public function test_replace_sync_rows_deletes_previous_topics(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'Old Chapter',
            'topic_name' => 'Old Topic',
            'learning_outcomes' => null,
            'remarks' => null,
        ]]);

        $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'We the Travellers—I',
            'topic_name' => 'Large Numbers in Travel',
            'learning_outcomes' => null,
            'remarks' => 'Head: Number System',
        ]], replaceExisting: true);

        $version = $version->fresh();
        $this->assertSame(1, $version->chapters()->count());
        $this->assertSame('We the Travellers—I', $version->chapters()->first()?->name);
        $this->assertSame(1, $version->chapters()->first()?->topics()->count());
        $this->assertSame('Large Numbers in Travel', $version->chapters()->first()?->topics()->first()?->name);
    }

    public function test_replace_sync_rows_with_empty_array_clears_syllabus(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'Old Chapter',
            'topic_name' => 'Old Topic',
            'learning_outcomes' => null,
            'remarks' => null,
        ]]);

        $service->syncRows($version, [], replaceExisting: true);

        $version = $version->fresh();
        $this->assertSame(0, $version->chapters()->count());
        $this->assertSame(SyllabusVersion::STATUS_DRAFT, $version->status);
    }

    public function test_class4_r1_excel_parses_with_trailing_space_chapter_column(): void
    {
        $path = base_path('tests/Class4_Math r1.xlsx');
        $this->assertFileExists($path);

        $service = app(SyllabusImportService::class);
        $file = new \Illuminate\Http\UploadedFile(
            $path,
            'Class4_Math r1.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $headerInfo = $service->describeFileHeaders($file);
        $rows = $service->parseFileToPreviewRows($file);

        $this->assertSame([], $headerInfo['missing']);
        $this->assertSame([], $headerInfo['unrecognized']);
        $this->assertSame(42, $rows->count());
        $this->assertSame('NCERT: Shapes Around Us', $rows->first()['remarks']);
        $this->assertTrue($rows->pluck('chapter_name')->contains('Geometry'));
    }

    public function test_class4_cbse_2026_excel_uses_chapter_name_column(): void
    {
        $path = base_path('samples/syllabus-import/CBSE_Class4_Maths_Syllabus_2026-27.xlsx');
        $this->assertFileExists($path);

        $service = app(SyllabusImportService::class);
        $file = new \Illuminate\Http\UploadedFile(
            $path,
            'CBSE_Class4_Maths_Syllabus_2026-27.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $headerInfo = $service->describeFileHeaders($file);
        $rows = $service->parseFileToPreviewRows($file);

        $this->assertSame([], $headerInfo['missing']);
        $this->assertSame([], $headerInfo['unrecognized']);
        $this->assertSame(42, $rows->count());
        $this->assertSame('Shapes Around Us', $rows->first()['chapter_name']);
        $this->assertSame('Geometry', $rows->first()['chapter_head_name']);
        $this->assertFalse($rows->pluck('chapter_name')->contains('Geometry'));
    }

    public function test_class5_r1_excel_parses_with_ncert_chapter_column(): void
    {
        $path = base_path('tests/Class5_Math r1.xlsx');
        $this->assertFileExists($path);

        $service = app(SyllabusImportService::class);
        $file = new \Illuminate\Http\UploadedFile(
            $path,
            'Class5_Math r1.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $headerInfo = $service->describeFileHeaders($file);
        $rows = $service->parseFileToPreviewRows($file);

        $this->assertSame([], $headerInfo['missing']);
        $this->assertSame([], $headerInfo['unrecognized']);
        $this->assertSame(45, $rows->count());
        $this->assertSame('We the Travellers—I', $rows->first()['chapter_name']);
        $this->assertTrue($rows->pluck('chapter_name')->contains('Grandmother\'s Quilt'));
    }

    public function test_final_class5_excel_maps_chapter_column_to_chapter_head(): void
    {
        $path = base_path('tests/FINAL 5.xlsx');
        $this->assertFileExists($path);

        ChapterHead::query()->create(['name' => 'Number System', 'sort_order' => 1]);
        ChapterHead::query()->create(['name' => 'Geometry', 'sort_order' => 2]);
        ChapterHead::query()->create(['name' => 'Ratio & Proportion', 'sort_order' => 3]);

        $service = app(SyllabusImportService::class);
        $file = new \Illuminate\Http\UploadedFile(
            $path,
            'FINAL 5.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );

        $rows = $service->parseFileToPreviewRows($file);

        $this->assertSame(45, $rows->count());
        $this->assertSame('We the Travellers—I', $rows->first()['chapter_name']);
        $this->assertSame('Number System', $rows->first()['chapter_head_name']);
        $this->assertNotNull($rows->first()['chapter_head_id']);
        $this->assertSame('', $rows->first()['remarks']);
        $this->assertSame('Geometry', $rows->firstWhere('chapter_name', 'Angles as Turns')['chapter_head_name']);
    }

    public function test_import_creates_missing_chapter_head_from_excel_column(): void
    {
        $version = $this->seedSyllabusVersion();
        $service = app(SyllabusImportService::class);

        $service->syncRows($version, [[
            'chapter_number' => '1',
            'chapter_name' => 'We the Travellers—I',
            'chapter_head_name' => 'Number System',
            'topic_name' => 'Large Numbers in Travel',
            'learning_outcomes' => null,
            'remarks' => null,
        ]], replaceExisting: true);

        $chapter = $version->fresh()->chapters()->first();

        $this->assertNotNull($chapter?->chapter_head_id);
        $this->assertSame('Number System', $chapter?->chapterHead?->name);
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
