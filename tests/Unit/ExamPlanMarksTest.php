<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ExamPlan;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Services\ExamPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamPlanMarksTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_save_exam_marks_on_past_plan(): void
    {
        [$enrollment, $chapter, $creator] = $this->seedContext();

        $service = app(ExamPlanService::class);

        $plan = $service->create($enrollment, $creator, [
            'exam_date' => now()->subWeek()->toDateString(),
            'title' => 'Unit test 1',
            'exam_type' => ExamPlan::TYPE_UNIT_TEST,
            'notes' => null,
        ], [
            ['syllabus_chapter_id' => $chapter->id, 'syllabus_topic_ids' => null],
        ]);

        $updated = $service->update($plan, [
            'exam_date' => $plan->exam_date->toDateString(),
            'title' => $plan->title,
            'exam_type' => $plan->exam_type,
            'notes' => null,
            'obtained_marks' => 42,
            'total_marks' => 50,
        ], [
            ['syllabus_chapter_id' => $chapter->id, 'syllabus_topic_ids' => null],
        ]);

        $formatted = $service->formatPlan($updated);

        $this->assertSame(42, $updated->obtained_marks);
        $this->assertSame(50, $updated->total_marks);
        $this->assertSame(ExamPlan::STATUS_COMPLETED, $updated->status);
        $this->assertTrue($formatted['has_marks']);
        $this->assertSame('84% (42/50)', $formatted['marks_score_label']);
    }

    /**
     * @return array{0: StudentEnrollment, 1: SyllabusChapter, 2: User}
     */
    private function seedContext(): array
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
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Test Student',
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

        $creator = User::factory()->create();

        return [$enrollment, $chapter, $creator];
    }
}
