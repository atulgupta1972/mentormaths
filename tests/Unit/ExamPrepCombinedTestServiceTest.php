<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ExamPlan;
use App\Models\GradeLevel;
use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\ExamPrepCombinedTestService;
use App\Support\WorksheetPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamPrepCombinedTestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_prefers_fill_blank_chunks_and_approve_assigns(): void
    {
        [$plan, $creator, $fillIds, $mcqIds] = $this->seedExamPrepContext(30, 10);

        $service = app(ExamPrepCombinedTestService::class);
        $preview = $service->preview($plan);

        $this->assertSame(40, $preview['available']);
        $this->assertSame(30, $preview['fill_blank_count']);
        $this->assertSame(10, $preview['mcq_count']);
        $this->assertSame(2, $preview['suggested_sets']);
        $this->assertSame(40, $preview['suggested_questions']);

        $created = $service->generateDrafts($plan, $creator);

        $this->assertCount(2, $created);
        $this->assertSame(25, $created[0]['questions_count']);
        $this->assertSame(15, $created[1]['questions_count']);
        $this->assertSame(25, $created[0]['fill_blank_count']);
        $this->assertSame(0, $created[0]['mcq_count']);
        $this->assertSame(15, $created[1]['fill_blank_count']);
        $this->assertSame(0, $created[1]['mcq_count']);

        $first = Worksheet::query()->findOrFail($created[0]['id']);
        $this->assertSame(WorksheetPurpose::EXAM_PREP, $first->purpose);
        $this->assertSame(Worksheet::STATUS_DRAFT, $first->status);
        $this->assertSame($plan->id, $first->exam_plan_id);

        $firstQuestionIds = $first->questions()->orderByPivot('sort_order')->pluck('questions.id')->all();
        $this->assertSame(
            array_slice($fillIds, 0, 25),
            $firstQuestionIds,
            'First set should be fill-in-the-blank failures first',
        );

        $approved = $service->approveAndAssign($first, $creator);

        $this->assertDatabaseHas('set_assignments', [
            'id' => $approved['assignment_id'],
            'worksheet_id' => $first->id,
            'student_enrollment_id' => $plan->student_enrollment_id,
            'exam_plan_id' => $plan->id,
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $this->assertSame(Worksheet::STATUS_PUBLISHED, $first->fresh()->status);
    }

    public function test_mcq_only_used_when_fill_blank_exhausted(): void
    {
        [$plan, $creator, $fillIds, $mcqIds] = $this->seedExamPrepContext(2, 3);

        $service = app(ExamPrepCombinedTestService::class);
        $created = $service->generateDrafts($plan, $creator);

        $this->assertCount(1, $created);
        // Numeric MCQs are auto-converted to fill-blank at generate time.
        $this->assertSame(5, $created[0]['fill_blank_count']);
        $this->assertSame(0, $created[0]['mcq_count']);

        $ws = Worksheet::query()->findOrFail($created[0]['id']);
        $ids = $ws->questions()->orderByPivot('sort_order')->pluck('questions.id')->all();
        $this->assertSame([...$fillIds, ...$mcqIds], $ids);
        $this->assertTrue(
            Question::query()->whereIn('id', $mcqIds)->get()->every(fn (Question $q) => $q->isFillInBlank()),
        );
    }

    /**
     * @return array{0: ExamPlan, 1: User, 2: list<int>, 3: list<int>}
     */
    private function seedExamPrepContext(int $fillCount, int $mcqCount): array
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
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Riya',
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

        $creator = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $plan = ExamPlan::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'exam_date' => now()->addWeek()->toDateString(),
            'title' => 'Half yearly',
            'exam_type' => ExamPlan::TYPE_HALF_YEARLY,
            'created_by' => $creator->id,
            'status' => ExamPlan::STATUS_PLANNED,
        ]);
        $plan->chapters()->attach($chapter->id);

        $fillIds = [];
        for ($i = 0; $i < $fillCount; $i++) {
            $q = Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'question_text' => "Fill {$i} + 1 = ___",
                'type' => Question::TYPE_FILL_IN_BLANK,
                'source' => Question::SOURCE_MANUAL,
            ]);
            $fillIds[] = $q->id;
            PracticeCorrectionItem::query()->create([
                'student_id' => $student->id,
                'question_id' => $q->id,
                'syllabus_chapter_id' => $chapter->id,
                'source_type' => PracticeCorrectionItem::SOURCE_BATCH_TEST,
                'failure_reason' => 'first_wrong',
                'status' => PracticeCorrectionItem::STATUS_PENDING,
                'first_failure_at' => now()->subDays($fillCount - $i),
            ]);
        }

        $mcqIds = [];
        for ($i = 0; $i < $mcqCount; $i++) {
            $q = Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'question_text' => "MCQ {$i}?",
                'type' => Question::TYPE_MCQ,
                'source' => Question::SOURCE_MANUAL,
            ]);
            QuestionOption::query()->create([
                'question_id' => $q->id,
                'option_text' => (string) (10 + $i),
                'is_correct' => false,
                'sort_order' => 1,
            ]);
            QuestionOption::query()->create([
                'question_id' => $q->id,
                'option_text' => (string) (20 + $i),
                'is_correct' => true,
                'sort_order' => 2,
            ]);
            $mcqIds[] = $q->id;
            PracticeCorrectionItem::query()->create([
                'student_id' => $student->id,
                'question_id' => $q->id,
                'syllabus_chapter_id' => $chapter->id,
                'source_type' => PracticeCorrectionItem::SOURCE_BATCH_TEST,
                'failure_reason' => 'first_wrong',
                'status' => PracticeCorrectionItem::STATUS_PENDING,
                'first_failure_at' => now()->subHours($mcqCount - $i),
            ]);
        }

        return [$plan->fresh(['chapters', 'enrollment.student']), $creator, $fillIds, $mcqIds];
    }
}
