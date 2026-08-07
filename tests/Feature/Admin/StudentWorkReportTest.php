<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
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
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StudentWorkReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_student_work_report_with_live_and_pending_data(): void
    {
        $this->withoutVite();

        [$grade, $enrollment, $admin, $assignment] = $this->seedStudentWithPendingWork();

        SetAttempt::query()->create([
            'set_assignment_id' => $assignment->id,
            'attempt_number' => 1,
            'mode' => SetAttempt::MODE_BATCH,
            'current_question_index' => 8,
            'started_at' => now()->subMinutes(5),
            'active_session_started_at' => now()->subMinutes(2),
            'status' => SetAttempt::STATUS_IN_PROGRESS,
        ]);

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.student-work-report.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/StudentWorkReport/Index')
                ->where('gradeLevel.id', $grade->id)
                ->where('report.summary.students_with_pending', 1)
                ->where('report.summary.students_live_now', 1)
                ->has('report.live', 1)
                ->where('report.live.0.progress_label', '9/10')
                ->has('report.students', 1)
                ->where('report.students.0.pending_count', 1));
    }

    public function test_admin_can_send_class_reminders_from_work_report(): void
    {
        Mail::fake();

        [$grade, $enrollment, $admin] = $this->seedStudentWithPendingWork();

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->post(route('admin.student-work-report.send-reminders'))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    /**
     * @return array{0: GradeLevel, 1: StudentEnrollment, 2: User, 3: SetAssignment}
     */
    private function seedStudentWithPendingWork(): array
    {
        $year = AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);

        $board = Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = GradeLevel::query()->create(['name' => 'Class 8', 'sort_order' => 8, 'is_active' => true]);
        $subject = Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);

        $syllabus = SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);

        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Algebra',
            'chapter_number' => 'Ch 3',
            'sort_order' => 3,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Expressions',
            'sort_order' => 1,
        ]);

        $studentUser = User::factory()->create(['role' => User::ROLE_STUDENT, 'email' => 'student@example.com']);
        $student = Student::query()->create([
            'user_id' => $studentUser->id,
            'name' => 'Report Student',
            'email' => 'student@example.com',
            'parent1_name' => 'Parent',
            'parent1_mobile' => '9876543210',
            'school_name' => 'Demo School',
        ]);

        $enrollment = StudentEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'board_id' => $board->id,
            'grade_level_id' => $grade->id,
            'school_name' => 'Demo School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Algebra practice 1',
            'set_code' => 'ALG-P1',
            'set_number' => 1,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
        ]);

        for ($i = 1; $i <= 10; $i++) {
            $question = Question::query()->create([
                'syllabus_topic_id' => $topic->id,
                'type' => Question::TYPE_MCQ,
                'question_text' => "Q{$i}",
                'source' => Question::SOURCE_MANUAL,
            ]);
            $worksheet->questions()->attach($question->id, ['sort_order' => $i]);
        }

        $assignment = SetAssignment::query()->create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $worksheet->id,
            'assigned_by' => User::factory()->create(['role' => User::ROLE_ADMIN])->id,
            'status' => SetAssignment::STATUS_IN_PROGRESS,
            'assigned_at' => now()->subDays(2),
            'due_date' => now()->addDay()->toDateString(),
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$grade, $enrollment, $admin, $assignment];
    }
}
