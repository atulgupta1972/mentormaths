<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\ClassAssignmentService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_set_status_board_lists_cross_grade_assignments(): void
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade7 = GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $grade8 = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus7 = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade7->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade8->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter7 = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus7->id,
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $topic7 = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter7->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Cross Grade Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade8->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet7 = Worksheet::query()->create([
            'title' => 'Class 7 sheet',
            'set_number' => 1,
            'set_code' => 'S711',
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic7->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $assigner = User::factory()->create(['role' => User::ROLE_ADMIN]);

        SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet7->id,
            'assigned_by' => $assigner->id,
            'assigned_at' => now(),
            'due_date' => now()->addWeek(),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        $board = app(ClassAssignmentService::class)->classSetStatusBoard($grade8, null, $board->id);

        $extraChapter = collect($board['chapters'])->firstWhere('is_extra', true);

        $this->assertNotNull($extraChapter);
        $this->assertSame('S711', $extraChapter['sets'][0]['set_code']);
        $this->assertTrue($extraChapter['sets'][0]['is_cross_grade']);
        $this->assertSame('Class 7', $extraChapter['sets'][0]['sheet_grade_name']);
    }
}
