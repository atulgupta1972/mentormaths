<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentChapterMetric;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TextbookChapterPracticeResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_remove_published_sets_and_student_scores_for_reupload(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $uploader = User::factory()->create(['role' => User::ROLE_CONTENT_UPLOADER]);
        [$chapter, $worksheet, $question, $shared, $attempt, $enrollment] = $this->seedPublishedChapter();

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'offered_amount_inr' => 1000,
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        StudentChapterMetric::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapter->syllabus_chapter_id,
            'performance' => ['scorePct' => 80, 'total' => 1, 'done' => 1, 'correct' => 1],
            'metrics_updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.textbooks.clear-published-practice', $chapter), [
                'confirm' => 'REMOVE',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('worksheets', ['id' => $worksheet->id]);
        $this->assertDatabaseMissing('questions', ['id' => $question->id]);
        $this->assertDatabaseHas('questions', ['id' => $shared->id]);
        $this->assertDatabaseMissing('set_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseMissing('set_assignments', ['worksheet_id' => $worksheet->id]);

        $chapter->refresh();
        $this->assertSame(TextbookChapter::STATUS_DRAFT, $chapter->status);
        $this->assertNull($chapter->extraction_items);
        $this->assertNull($chapter->mcq_worksheet_id);
        $this->assertSame([], $chapter->mcqWorksheetIds());

        $task->refresh();
        $this->assertSame(ContentUploadTask::STATUS_IN_PROGRESS, $task->status);
        $this->assertNull($task->published_at);

        $metric = StudentChapterMetric::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('syllabus_chapter_id', $chapter->syllabus_chapter_id)
            ->first();
        $this->assertNotNull($metric);
        $this->assertSame(0, (int) $metric->performance['done']);
        $this->assertSame(0, (int) $metric->performance['correct']);
        $this->assertSame(0, (int) $metric->performance['scorePct']);
        $this->assertSame(0, (int) $metric->performance['setDone']);
        $this->assertSame(1, (int) $metric->performance['total']);

        $this->actingAs($admin)
            ->get(route('admin.textbooks.show', $chapter))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Textbooks/Show')
                ->where('chapter.status', TextbookChapter::STATUS_DRAFT)
                ->where('publishedAttemptCount', 0)
                ->where('publishedPracticeSets', [])
            );
    }

    public function test_clear_requires_the_word_remove(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        [$chapter, $worksheet] = $this->seedPublishedChapter();

        $this->actingAs($admin)
            ->from(route('admin.textbooks.show', $chapter))
            ->post(route('admin.textbooks.clear-published-practice', $chapter), [
                'confirm' => 'yes',
            ])
            ->assertRedirect(route('admin.textbooks.show', $chapter))
            ->assertSessionHasErrors('confirm');

        $this->assertDatabaseHas('worksheets', ['id' => $worksheet->id]);
    }

    /**
     * @return array{0: TextbookChapter, 1: Worksheet, 2: Question, 3: Question, 4: SetAttempt, 5: StudentEnrollment}
     */
    private function seedPublishedChapter(): array
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-04-01',
            'ends_on' => '2027-03-31',
            'is_active' => true,
        ]);
        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 9', 'sort_order' => 9, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics', 'is_active' => true]);
        $version = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'subject_id' => $subject->id,
            'name' => 'CBSE Class 9',
            'is_active' => true,
        ]);
        $syllabusChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'chapter_number' => 2,
            'name' => 'Quadrilaterals',
            'sort_order' => 2,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $syllabusChapter->id,
            'topic_number' => 1,
            'name' => 'Intro',
            'sort_order' => 1,
        ]);
        $worksheet = Worksheet::query()->create([
            'title' => 'Quadrilaterals MCQ',
            'set_number' => 1,
            'set_code' => 'C9-GM22-CH02-M1',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $admin->id,
        ]);
        $other = Worksheet::query()->create([
            'title' => 'Other set',
            'set_number' => 9,
            'set_code' => 'C9-OTHER-M1',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::STANDARD,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $admin->id,
        ]);
        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Only on this chapter',
            'type' => Question::TYPE_MCQ,
            'difficulty' => 'medium',
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
            'created_by' => $admin->id,
        ]);
        $shared = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'question_text' => 'Shared with another set',
            'type' => Question::TYPE_MCQ,
            'difficulty' => 'medium',
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
            'created_by' => $admin->id,
        ]);
        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);
        $worksheet->questions()->attach($shared->id, ['sort_order' => 2]);
        $other->questions()->attach($shared->id, ['sort_order' => 1]);

        $student = Student::query()->create([
            'name' => 'Attempted student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);
        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => $admin->id,
            'assigned_at' => now(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);
        $attempt = SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'score' => 8,
            'max_score' => 10,
            'status' => SetAttempt::STATUS_SUBMITTED,
        ]);

        $textbook = Textbook::query()->create([
            'name' => 'Ganita Manjari 2',
            'code' => 'GM22',
            'grade_level_id' => $grade->id,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        $chapter = TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => 2,
            'title' => 'Quadrilaterals',
            'status' => TextbookChapter::STATUS_PUBLISHED,
            'extraction_items' => [['question_text' => 'old sum without a figure']],
            'mcq_set_plan' => [[
                'set_code' => 'C9-GM22-CH02-M1',
                'q_from' => 1,
                'q_to' => 2,
                'description' => '',
            ]],
            'mcq_worksheet_id' => $worksheet->id,
            'mcq_worksheet_ids' => [$worksheet->id],
            'published_at' => now(),
            'created_by' => $admin->id,
        ]);

        return [$chapter, $worksheet, $question, $shared, $attempt, $enrollment];
    }
}
