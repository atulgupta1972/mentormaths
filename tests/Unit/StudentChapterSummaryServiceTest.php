<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\StudentChapterSummaryService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentChapterSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapter_summary_counts_available_sets_and_progress_labels(): void
    {
        [$enrollment, $chapter, $practiceOne, $practiceTwo, $testWorksheet] = $this->seedChapterContent();

        $completedAssignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $practiceOne->id,
            'assigned_at' => now()->subDay(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        SetAttempt::query()->create([
            'set_assignment_id' => $completedAssignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_GUIDED,
            'started_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(20),
            'score' => 8,
            'max_score' => 10,
            'time_seconds' => 600,
            'status' => SetAttempt::STATUS_SUBMITTED,
            'submission_timing' => SetAttempt::TIMING_ON_TIME,
        ]);

        $summary = app(StudentChapterSummaryService::class)->forEnrollment($enrollment);

        $this->assertCount(1, $summary['chapters']);
        $row = $summary['chapters'][0];
        $this->assertSame($chapter->id, $row['id']);
        $this->assertSame(2, $row['counts']['practice']);
        $this->assertSame(1, $row['counts']['test']);
        $this->assertSame('DONE(80%)', $row['items']['practice'][0]['status_label']);
        $this->assertSame('NOT DONE', $row['items']['practice'][1]['status_label']);
        $this->assertTrue($row['items']['practice'][1]['can_assign']);
    }

    public function test_student_can_self_assign_worksheet_from_dashboard_route(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);

        [$enrollment, , $practiceOne] = $this->seedChapterContent();
        $studentUser = $this->studentUserForEnrollment($enrollment);

        $this->actingAs($studentUser)
            ->post(route('student.worksheets.self-assign', $practiceOne))
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('set_assignments', [
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $practiceOne->id,
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);
    }

    public function test_dashboard_includes_chapter_summary_for_students(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);

        [$enrollment] = $this->seedChapterContent();
        $studentUser = $this->studentUserForEnrollment($enrollment);

        $this->withoutVite()
            ->actingAs($studentUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('chapterSummary.chapters', 1));
    }

    private function studentUserForEnrollment(StudentEnrollment $enrollment): User
    {
        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT]);
        Student::query()->whereKey($enrollment->student_id)->update(['user_id' => $studentUser->id]);

        return $studentUser->fresh()->load('student');
    }

    /**
     * @return array{
     *     0: StudentEnrollment,
     *     1: SyllabusChapter,
     *     2: Worksheet,
     *     3: Worksheet,
     *     4: Worksheet
     * }
     */
    private function seedChapterContent(): array
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
            'name' => 'Algebraic Expressions',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Terms and coefficients',
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Chapter Summary Student',
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

        $practiceOne = Worksheet::query()->create([
            'title' => 'Practice 1',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $practiceTwo = Worksheet::query()->create([
            'title' => 'Practice 2',
            'set_number' => 2,
            'set_code' => 'S712',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $testWorksheet = Worksheet::query()->create([
            'title' => 'Chapter test',
            'set_number' => 1,
            'set_code' => 'T711',
            'tier' => PracticeSetTier::CHAPTER_TEST,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        return [$enrollment, $chapter, $practiceOne, $practiceTwo, $testWorksheet];
    }
}
