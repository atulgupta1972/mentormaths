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
use App\Models\User;
use App\Services\QuestionZipImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class QuestionZipImportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_fill_blank_chapter_pack_with_diagrams(): void
    {
        [$chapter, $topic, $user] = $this->seedChapterContext();

        $zipPath = $this->createSampleZip([
            'questions' => [[
                'topic' => $topic->name,
                'question' => 'In the figure, ∠AOC = 58°, then ∠BOD = ____°.',
                'diagram_file' => 'q1.jpg',
                'answer_format' => 'integer',
                'correct_answer' => '58',
                'method_hint' => 'Vertically opposite angles are equal.',
                'explanation' => '∠BOD = 58°.',
                'difficulty' => 'Medium',
            ]],
        ], [
            'q1.jpg' => $this->minimalJpegBytes(),
        ]);

        $file = new UploadedFile($zipPath, 'pack.zip', 'application/zip', null, true);

        $result = app(QuestionZipImportService::class)->importPack(
            $file,
            $user,
            null,
            $chapter,
        );

        $this->assertSame(QuestionZipImportService::TYPE_FILL_IN_BLANK, $result['type']);
        $this->assertCount(1, $result['saved']);
        $this->assertSame(1, $result['diagram_count']);

        $question = Question::query()->with('topic')->first();
        $this->assertNotNull($question);
        $this->assertSame($topic->id, $question->syllabus_topic_id);
        $this->assertNotNull($question->diagram_path);
        $this->assertTrue(Storage::disk('public')->exists($question->diagram_path));
        $this->assertTrue($question->isFillInBlank());
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $images
     */
    private function createSampleZip(array $payload, array $images = []): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'mentor-import-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('questions.json', json_encode($payload, JSON_THROW_ON_ERROR));

        foreach ($images as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();

        return $zipPath;
    }

    private function minimalJpegBytes(): string
    {
        return base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//2wBDAQoLCw4NDx0QEB0VICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=',
            true,
        ) ?: '';
    }

    /**
     * @return array{0: SyllabusChapter, 1: SyllabusTopic, 2: User}
     */
    private function seedChapterContext(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE']);
        $grade = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Lines and Angles',
            'chapter_number' => 5,
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Related Angles',
            'sort_order' => 1,
        ]);

        $user = User::factory()->create();

        return [$chapter, $topic, $user];
    }
}
