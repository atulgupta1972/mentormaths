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
use App\Support\WorksheetPurpose;
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
        $this->assertSame(now()->addWeek()->toDateString(), $row['items']['practice'][0]['target_date']);
        $this->assertSame('NOT DONE', $row['items']['practice'][1]['status_label']);
        $this->assertNull($row['items']['practice'][1]['target_date']);
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

    public function test_school_study_plan_includes_chapter_availability(): void
    {
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);

        [$enrollment] = $this->seedChapterContent();
        $studentUser = $this->studentUserForEnrollment($enrollment);

        $this->withoutVite()
            ->actingAs($studentUser)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/SchoolStudyPlan')
                ->has('classCoverage.chapters', 1)
                ->has('classCoverage.availability_columns')
                ->where('classCoverage.chapters.0.availability.practice', 2)
                ->where('classCoverage.chapters.0.availability.test', 1)
                ->has('classCoverage.chapters.0.items'));
    }

    public function test_chapter_summary_includes_formula_sets(): void
    {
        [$enrollment, $chapter] = $this->seedChapterContent(withFormula: true);

        $summary = app(StudentChapterSummaryService::class)->forEnrollment($enrollment);

        $row = $summary['chapters'][0];
        $this->assertSame(1, $row['counts']['formula']);
        $this->assertSame('Fm3', $row['items']['formula'][0]['short_label']);
    }

    public function test_filter_options_default_to_student_class_and_board(): void
    {
        [$enrollment] = $this->seedChapterContent();

        $filters = app(StudentChapterSummaryService::class)->filterOptions($enrollment);

        $this->assertSame($enrollment->grade_level_id, $filters['selected_grade_level_id']);
        $this->assertSame($enrollment->board_id, $filters['selected_board_id']);
        $this->assertSame($enrollment->grade_level_id, $filters['home_grade_level_id']);
        $this->assertSame($enrollment->board_id, $filters['home_board_id']);
    }

    public function test_chapter_summary_can_show_other_grade_syllabus(): void
    {
        [$enrollment, $chapter] = $this->seedChapterContent(withOtherGrade: true);

        $otherGrade = GradeLevel::query()->where('name', 'Class 6')->firstOrFail();

        $summary = app(StudentChapterSummaryService::class)->forEnrollment(
            $enrollment,
            $otherGrade->id,
            $enrollment->board_id,
        );

        $this->assertFalse($summary['context']['is_home_class']);
        $this->assertSame('Class 6', $summary['context']['selected_grade_name']);
        $this->assertCount(1, $summary['chapters']);
        $this->assertSame('Integers', $summary['chapters'][0]['name']);
    }

    public function test_cross_class_assignment_merges_by_chapter_name_or_lands_in_other(): void
    {
        [$enrollment, $homeChapter] = $this->seedChapterContent(withOtherGrade: true);

        $board = Board::query()->where('code', 'CBSE')->firstOrFail();
        $classSix = GradeLevel::query()->where('name', 'Class 6')->firstOrFail();

        $syllabusSix = SyllabusVersion::query()
            ->where('grade_level_id', $classSix->id)
            ->where('board_id', $board->id)
            ->firstOrFail();

        $matchingSixChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabusSix->id,
            'name' => 'Algebraic Expressions',
            'chapter_number' => 2,
            'sort_order' => 2,
        ]);
        $matchingTopic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $matchingSixChapter->id,
            'name' => 'Like terms',
            'sort_order' => 1,
        ]);
        $matchedWorksheet = Worksheet::query()->create([
            'title' => 'Class 6 algebra practice',
            'set_number' => 9,
            'set_code' => 'S609',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $matchingTopic->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $otherSixChapter = SyllabusChapter::query()->where('name', 'Integers')->firstOrFail();
        $otherTopic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $otherSixChapter->id,
            'name' => 'Negative numbers',
            'sort_order' => 1,
        ]);
        $otherWorksheet = Worksheet::query()->create([
            'title' => 'Class 6 integers practice',
            'set_number' => 3,
            'set_code' => 'S603',
            'tier' => PracticeSetTier::BUILDER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $otherTopic->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $matchedWorksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addDays(3),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);
        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $otherWorksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addDays(3),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $summary = app(StudentChapterSummaryService::class)->forEnrollment($enrollment);
        $homeRow = collect($summary['chapters'])->firstWhere('id', $homeChapter->id);

        $this->assertSame(3, $homeRow['counts']['practice']);
        $this->assertTrue(
            collect($homeRow['items']['practice'])->contains(fn (array $item) => $item['worksheet_id'] === $matchedWorksheet->id),
        );

        $this->assertCount(1, $summary['other_groups']);
        $this->assertSame('Class 6 — Integers', $summary['other_groups'][0]['label']);
        $this->assertSame($otherWorksheet->id, $summary['other_groups'][0]['items'][0]['worksheet_id']);
    }

    public function test_cross_board_assignment_lands_in_additional_not_auto_merged(): void
    {
        [$enrollment, $homeChapter] = $this->seedChapterContent(withOtherGrade: true);

        $icse = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);
        $classSeven = GradeLevel::query()->firstOrCreate(
            ['name' => 'Class 7'],
            ['sort_order' => 7, 'is_active' => true],
        );
        $year = $enrollment->academic_year_id
            ? \App\Models\AcademicYear::query()->find($enrollment->academic_year_id)
            : \App\Models\AcademicYear::query()->firstOrFail();
        $subject = \App\Models\Subject::query()->where('code', 'MATHS')->firstOrFail();

        $icseSyllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $classSeven->id,
            'board_id' => $icse->id,
            'subject_id' => $subject->id,
        ]);
        $icseChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $icseSyllabus->id,
            'name' => $homeChapter->name,
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);
        $icseTopic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $icseChapter->id,
            'name' => 'ICSE topic',
            'sort_order' => 1,
        ]);
        $icseWorksheet = Worksheet::query()->create([
            'title' => 'ICSE practice',
            'set_number' => 4,
            'set_code' => 'I704',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $icseTopic->id,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $icseWorksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addDays(3),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $summary = app(StudentChapterSummaryService::class)->forEnrollment($enrollment);
        $homeRow = collect($summary['chapters'])->firstWhere('id', $homeChapter->id);

        $this->assertFalse(
            collect($homeRow['items']['practice'] ?? [])->contains(
                fn (array $item) => $item['worksheet_id'] === $icseWorksheet->id,
            ),
        );
        $this->assertTrue(
            collect($summary['other_groups'])->contains(
                fn (array $group) => collect($group['items'])->contains(
                    fn (array $item) => $item['worksheet_id'] === $icseWorksheet->id,
                ),
            ),
        );
        $this->assertStringContainsString('ICSE', $summary['other_groups'][0]['label']);
        $this->assertStringContainsString('Class 7', $summary['other_groups'][0]['label']);
    }

    public function test_multipart_textbook_written_sheets_appear_under_books(): void
    {
        [$enrollment, $chapter] = $this->seedChapterContent();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $grade = $enrollment->gradeLevel;
        $board = $enrollment->board;

        $textbook = \App\Models\Textbook::query()->create([
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'name' => 'NCERT Exemplar',
            'code' => 'EX',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $writtenOne = Worksheet::query()->create([
            'title' => 'Written part 1',
            'set_number' => 1,
            'set_code' => 'W701',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => \App\Support\WrittenSheetStatus::VERIFIED,
            'written_pdf_path' => 'written/w1.pdf',
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);
        $writtenTwo = Worksheet::query()->create([
            'title' => 'Written part 2',
            'set_number' => 2,
            'set_code' => 'W702',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => \App\Support\WrittenSheetStatus::VERIFIED,
            'written_pdf_path' => 'written/w2.pdf',
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $admin->id,
        ]);

        \App\Models\TextbookChapter::query()->create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $chapter->id,
            'chapter_number' => 1,
            'title' => 'Integers',
            'status' => \App\Models\TextbookChapter::STATUS_PUBLISHED,
            'written_worksheet_id' => $writtenOne->id,
            'written_worksheet_ids' => [$writtenOne->id, $writtenTwo->id],
            'created_by' => $admin->id,
        ]);

        $summary = app(StudentChapterSummaryService::class)->forEnrollment($enrollment);
        $row = collect($summary['chapters'])->firstWhere('id', $chapter->id);
        $bookItems = collect($row['items']['books'][(string) $textbook->id] ?? []);

        $this->assertCount(2, $bookItems);
        $this->assertTrue($bookItems->contains(fn (array $item) => $item['worksheet_id'] === $writtenOne->id));
        $this->assertTrue($bookItems->contains(fn (array $item) => $item['worksheet_id'] === $writtenTwo->id));
        $this->assertTrue($bookItems->every(fn (array $item) => ($item['kind'] ?? null) === 'written'));
        // Textbook-linked written must not also appear in the Written tier row.
        $this->assertFalse(
            collect($row['items']['written'])->contains(fn (array $item) => in_array($item['worksheet_id'], [$writtenOne->id, $writtenTwo->id], true)),
        );
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
    private function seedChapterContent(
        bool $withFormula = false,
        bool $withOtherGrade = false,
    ): array {
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

        $formulaWorksheet = null;

        if ($withFormula) {
            $formulaWorksheet = Worksheet::query()->create([
                'title' => 'Formula recall 1',
                'set_number' => 3,
                'set_code' => 'FM713',
                'purpose' => WorksheetPurpose::FORMULA,
                'tier' => PracticeSetTier::STARTER,
                'scope' => PracticeSetScope::TOPIC,
                'syllabus_topic_id' => $topic->id,
                'delivery_mode' => WorksheetDeliveryMode::ONLINE,
                'status' => Worksheet::STATUS_PUBLISHED,
            ]);
        }

        if ($withOtherGrade) {
            $classSix = GradeLevel::query()->create(['name' => 'Class 6', 'sort_order' => 6, 'is_active' => true]);

            $syllabusSix = SyllabusVersion::query()->create([
                'academic_year_id' => $year->id,
                'grade_level_id' => $classSix->id,
                'board_id' => $board->id,
                'subject_id' => $subject->id,
            ]);

            SyllabusChapter::query()->create([
                'syllabus_version_id' => $syllabusSix->id,
                'name' => 'Integers',
                'chapter_number' => 1,
                'sort_order' => 1,
            ]);
        }

        return [$enrollment, $chapter, $practiceOne, $practiceTwo, $testWorksheet];
    }
}
