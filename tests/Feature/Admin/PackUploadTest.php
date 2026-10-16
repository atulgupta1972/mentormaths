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
use App\Services\AdminGradeContext;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use ZipArchive;

class PackUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_pack_upload_page(): void
    {
        $this->withoutVite();

        [$admin, $grade, $chapter] = $this->seedContext();

        $this->actingAs($admin)
            ->withSession([AdminGradeContext::SESSION_KEY => $grade->id])
            ->get(route('admin.questions.pack-upload', ['syllabus_chapter_id' => $chapter->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Questions/PackUpload')
                ->where('selectedChapterId', $chapter->id)
                ->has('masterProfiles', 3)
            );
    }

    public function test_admin_can_upload_fill_blank_pack_as_learner_set(): void
    {
        $this->withoutVite();

        [$admin, $grade, $chapter, $topic] = $this->seedContext();

        $zipPath = $this->createSampleZip([
            'questions' => [[
                'topic' => $topic->name,
                'question' => '(-12) + 8 = ____',
                'answer_format' => 'integer',
                'correct_answer' => '-4',
                'explanation' => 'Final answer = -4',
                'difficulty' => 'Easy',
            ]],
        ]);

        $response = $this->actingAs($admin)
            ->withSession([AdminGradeContext::SESSION_KEY => $grade->id])
            ->post(route('admin.questions.pack-upload.store'), [
                'pack' => new UploadedFile($zipPath, 'pack.zip', 'application/zip', null, true),
                'syllabus_chapter_id' => $chapter->id,
                'syllabus_topic_id' => $topic->id,
                'output_type' => 'fill_in_blank',
                'master_profile' => PracticeSetTier::STARTER,
            ]);

        $worksheet = Worksheet::query()->first();
        $this->assertNotNull($worksheet);
        $this->assertSame(PracticeSetTier::STARTER, $worksheet->tier);
        $this->assertSame(Worksheet::STATUS_PUBLISHED, $worksheet->status);
        $this->assertSame(1, Question::query()->count());
        $this->assertTrue(Question::query()->first()->isFillInBlank());

        $response->assertRedirect(route('admin.questions.sets.show', $worksheet));
    }

    public function test_admin_can_upload_written_pack_with_pdf(): void
    {
        $this->withoutVite();

        [$admin, $grade, $chapter, $topic] = $this->seedContext();

        $zipPath = $this->createSampleZip([
            'questions' => [[
                'topic' => $topic->name,
                'question' => 'Solve: x + 3 = 7. x = ____',
                'answer_format' => 'integer',
                'correct_answer' => '4',
                'explanation' => 'Final answer = 4',
                'difficulty' => 'Easy',
            ]],
        ], [
            'sheet.pdf' => '%PDF-1.4 minimal test pdf',
        ]);

        $response = $this->actingAs($admin)
            ->withSession([AdminGradeContext::SESSION_KEY => $grade->id])
            ->post(route('admin.questions.pack-upload.store'), [
                'pack' => new UploadedFile($zipPath, 'pack.zip', 'application/zip', null, true),
                'syllabus_chapter_id' => $chapter->id,
                'syllabus_topic_id' => $topic->id,
                'output_type' => 'written',
                'master_profile' => PracticeSetTier::BUILDER,
            ]);

        $worksheet = Worksheet::query()->first();
        $this->assertNotNull($worksheet);
        $this->assertSame(WorksheetDeliveryMode::WRITTEN, $worksheet->delivery_mode);
        $this->assertSame(PracticeSetTier::BUILDER, $worksheet->tier);
        $this->assertNotNull($worksheet->written_pdf_path);
        $this->assertSame(1, $worksheet->questions()->count());

        $response->assertRedirect(route('admin.written-sheets.show', $worksheet));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $extraFiles
     */
    private function createSampleZip(array $payload, array $extraFiles = []): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'pack-upload-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('questions.json', json_encode($payload, JSON_THROW_ON_ERROR));

        foreach ($extraFiles as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();

        return $zipPath;
    }

    /**
     * @return array{0: User, 1: GradeLevel, 2: SyllabusChapter, 3: SyllabusTopic}
     */
    private function seedContext(): array
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
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'name' => 'Integers',
            'chapter_number' => '1',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$admin, $grade, $chapter, $topic];
    }
}
