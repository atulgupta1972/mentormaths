<?php

namespace Tests\Feature\Student;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ExamPlan;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentChapterTerm;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ChapterTermMapGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('exam_gates.first_term_marks_required', true);
        Config::set('exam_gates.chapter_terms_required', true);
    }

    public function test_after_marks_student_is_blocked_from_sums_until_all_chapters_mapped(): void
    {
        [$user, $chapters, $enrollment] = $this->seedStudentWithTerm1Marks();

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.school-study-plan.show'));

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/SchoolStudyPlan')
                ->where('chapterTermMapGate.required', true)
                ->where('chapterTermMapGate.satisfied', false)
                ->where('chapterTermMapGate.unmapped_chapters', 3));

        // Map only term 1 — still blocked (need both terms).
        $this->actingAs($user)
            ->put(route('student.school-study-plan.terms'), [
                'items' => [
                    ['chapter_id' => $chapters[0]->id, 'term' => 1],
                    ['chapter_id' => $chapters[1]->id, 'term' => 1],
                    ['chapter_id' => $chapters[2]->id, 'term' => 1],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.school-study-plan.show'));

        // Full map with both terms.
        $this->actingAs($user)
            ->put(route('student.school-study-plan.terms'), [
                'items' => [
                    ['chapter_id' => $chapters[0]->id, 'term' => 1],
                    ['chapter_id' => $chapters[1]->id, 'term' => 1],
                    ['chapter_id' => $chapters[2]->id, 'term' => 2],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('student_chapter_terms', [
            'student_enrollment_id' => $enrollment->id,
            'syllabus_chapter_id' => $chapters[2]->id,
            'term' => StudentChapterTerm::TERM_SECOND,
        ]);

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('chapterTermMapGate.satisfied', true)
                ->where('chapterTermMapGate.unmapped_chapters', 0));
    }

    public function test_marks_gate_still_runs_before_term_mapping(): void
    {
        [$user] = $this->seedStudent(withMarks: false);

        $this->actingAs($user)
            ->get(route('student.school-study-plan.show'))
            ->assertRedirect(route('student.exams.index'));
    }

    /**
     * @return array{0: User, 1: list<SyllabusChapter>, 2: StudentEnrollment}
     */
    private function seedStudentWithTerm1Marks(): array
    {
        [$user, $chapters, $enrollment] = $this->seedStudent(withMarks: true);

        return [$user, $chapters, $enrollment];
    }

    /**
     * @return array{0: User, 1: list<SyllabusChapter>, 2: StudentEnrollment}
     */
    private function seedStudent(bool $withMarks): array
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

        $chapters = [];
        foreach (['Integers', 'Fractions', 'Triangles'] as $i => $name) {
            $chapters[] = SyllabusChapter::query()->create([
                'syllabus_version_id' => $syllabus->id,
                'name' => $name,
                'chapter_number' => (string) ($i + 1),
                'sort_order' => $i + 1,
            ]);
        }

        $user = User::factory()->create(['role' => User::ROLE_STUDENT]);
        $student = Student::query()->create([
            'user_id' => $user->id,
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

        if ($withMarks) {
            ExamPlan::query()->create([
                'student_enrollment_id' => $enrollment->id,
                'exam_date' => now()->subDays(2)->toDateString(),
                'title' => 'Half yearly',
                'exam_type' => ExamPlan::TYPE_HALF_YEARLY,
                'obtained_marks' => 60,
                'total_marks' => 100,
                'marks_entered_at' => now(),
                'created_by' => $user->id,
                'status' => ExamPlan::STATUS_COMPLETED,
            ]);
        }

        return [$user, $chapters, $enrollment];
    }
}
