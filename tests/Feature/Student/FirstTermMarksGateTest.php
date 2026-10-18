<?php

namespace Tests\Feature\Student;

use App\Mail\ExamMarksEnteredAdmin;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FirstTermMarksGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('exam_gates.first_term_marks_required', true);
    }

    public function test_student_without_term1_marks_is_blocked_from_drill(): void
    {
        [$user] = $this->seedStudent();

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.exams.index'));
    }

    public function test_student_can_open_exams_without_term1_marks(): void
    {
        [$user] = $this->seedStudent();

        $this->actingAs($user)
            ->get(route('student.exams.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/Exams/Index')
                ->where('firstTermMarksGate.required', true)
                ->where('firstTermMarksGate.satisfied', false));
    }

    public function test_entering_term1_marks_unblocks_and_emails_admin(): void
    {
        Mail::fake();
        Config::set('mail.registration_notify', 'admin@example.com');

        [$user, $chapter, $enrollment] = $this->seedStudent();

        $plan = ExamPlan::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'exam_date' => now()->subDays(3)->toDateString(),
            'title' => 'Half yearly',
            'exam_type' => ExamPlan::TYPE_HALF_YEARLY,
            'created_by' => $user->id,
            'status' => ExamPlan::STATUS_PLANNED,
        ]);
        $plan->chapters()->attach($chapter->id);

        $this->actingAs($user)
            ->get(route('student.formula-drill.show'))
            ->assertRedirect(route('student.exams.index'));

        $this->actingAs($user)
            ->put(route('student.exam-plans.update', $plan), [
                'exam_date' => $plan->exam_date->toDateString(),
                'title' => $plan->title,
                'exam_type' => ExamPlan::TYPE_HALF_YEARLY,
                'notes' => null,
                'obtained_marks' => 72,
                'total_marks' => 100,
                'chapter_selections' => [
                    ['syllabus_chapter_id' => $chapter->id, 'syllabus_topic_ids' => null],
                ],
            ])
            ->assertRedirect(route('student.exams.index'));

        $plan->refresh();
        $this->assertSame(72, $plan->obtained_marks);
        $this->assertSame(100, $plan->total_marks);
        $this->assertNotNull($plan->marks_entered_at);

        Mail::assertSent(ExamMarksEnteredAdmin::class, function (ExamMarksEnteredAdmin $mail) {
            return $mail->hasTo('admin@example.com')
                && (int) $mail->examPlan->obtained_marks === 72;
        });

        $this->actingAs($user)
            ->get(route('student.exams.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('firstTermMarksGate.satisfied', true));
    }

    public function test_past_half_yearly_requires_marks_on_save(): void
    {
        [$user, $chapter] = $this->seedStudent();

        $this->actingAs($user)
            ->post(route('student.exam-plans.store'), [
                'exam_date' => now()->subWeek()->toDateString(),
                'title' => 'Term 1',
                'exam_type' => ExamPlan::TYPE_HALF_YEARLY,
                'chapter_selections' => [
                    ['syllabus_chapter_id' => $chapter->id, 'syllabus_topic_ids' => null],
                ],
            ])
            ->assertSessionHasErrors(['obtained_marks', 'total_marks']);
    }

    /**
     * @return array{0: User, 1: SyllabusChapter, 2: StudentEnrollment}
     */
    private function seedStudent(): array
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

        return [$user, $chapter, $enrollment];
    }
}
