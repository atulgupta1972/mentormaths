<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\ClassCoverageService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossBoardAssignmentStudyGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_board_sheet_is_allowed_when_study_plan_started_even_without_name_match(): void
    {
        [$enrollment, $homeChapter, $foreignWorksheet, $assignment] = $this->seedCrossBoardAssignment();

        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $homeChapter->id,
            'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
        ]);

        $allowed = app(ClassCoverageService::class)->enrollmentCanAttemptContent(
            $enrollment,
            $foreignWorksheet,
            $assignment,
        );

        $this->assertTrue($allowed);
    }

    public function test_effective_chapter_override_requires_that_home_chapter_marked(): void
    {
        [$enrollment, $homeChapter, $foreignWorksheet, $assignment] = $this->seedCrossBoardAssignment();

        $assignment->update(['effective_syllabus_chapter_id' => $homeChapter->id]);

        $service = app(ClassCoverageService::class);

        $this->assertFalse($service->enrollmentCanAttemptContent(
            $enrollment,
            $foreignWorksheet,
            $assignment->fresh(),
        ));

        StudentChapterCoverage::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $homeChapter->id,
            'status' => StudentChapterCoverage::STATUS_STUDIED,
        ]);

        $this->assertTrue($service->enrollmentCanAttemptContent(
            $enrollment,
            $foreignWorksheet,
            $assignment->fresh(),
        ));

        $this->assertSame(
            $homeChapter->id,
            $service->resolveEffectiveSyllabusChapterId(
                $foreignWorksheet,
                $enrollment,
                $assignment->fresh(),
            ),
        );
    }

    /**
     * @return array{0: StudentEnrollment, 1: SyllabusChapter, 2: Worksheet, 3: SetAssignment}
     */
    private function seedCrossBoardAssignment(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $cbse = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $icse = Board::query()->create(['code' => 'ICSE', 'name' => 'ICSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 6', 'sort_order' => 6, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $cbseSyllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $cbse->id,
            'subject_id' => $subject->id,
        ]);
        $icseSyllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $icse->id,
            'subject_id' => $subject->id,
        ]);

        $homeChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $cbseSyllabus->id,
            'name' => 'Fractions',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);
        $foreignChapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $icseSyllabus->id,
            'name' => 'Decimals',
            'chapter_number' => 2,
            'sort_order' => 2,
        ]);
        $foreignTopic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $foreignChapter->id,
            'name' => 'Place value',
            'sort_order' => 1,
        ]);

        $student = Student::query()->create([
            'name' => 'Cross Board Student',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'School',
        ]);
        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $cbse->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'ICSE decimals test',
            'set_number' => 1,
            'set_code' => 'T601',
            'tier' => PracticeSetTier::CHAPTER_TEST,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $foreignTopic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
        ]);

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_at' => now(),
            'due_date' => now()->addDays(2),
            'status' => SetAssignment::STATUS_ASSIGNED,
        ]);

        return [$enrollment, $homeChapter, $worksheet, $assignment];
    }
}
