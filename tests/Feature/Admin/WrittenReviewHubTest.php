<?php

namespace Tests\Feature\Admin;

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
use App\Models\WrittenSubmission;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrittenReviewHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_written_review_hub_for_class(): void
    {
        $this->withoutVite();

        [$admin, $grade, $board] = $this->seedAdminClass();

        $this->actingAs($admin)
            ->withSession(['admin_grade_level_id' => $grade->id])
            ->get(route('admin.written-review.index', [
                'grade_level_id' => $grade->id,
                'board_id' => $board->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/WrittenReview/Index')
                ->where('gradeLevel.id', $grade->id)
                ->has('queue')
                ->has('setStatusBoard.chapters'));
    }

    public function test_student_written_index_shows_under_review_bucket(): void
    {
        $this->withoutVite();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);

        [$studentUser, $assignment] = $this->seedStudentWrittenAssignment();

        WrittenSubmission::query()->create([
            'set_assignment_id' => $assignment->id,
            'status' => WrittenSubmission::STATUS_UPLOADED,
            'uploaded_at' => now(),
            'upload_paths' => ['written/test.jpg'],
        ]);

        $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);

        $response = $this->actingAs($studentUser)
            ->get(route('student.written-assignments.index'));

        if ($response->status() !== 200) {
            $this->fail('Unexpected status '.$response->status().' redirect to '.$response->headers->get('Location').' flash='.json_encode(session()->all()));
        }

        $response
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/WrittenSheets/Index')
                ->where('counts.under_review', 1)
                ->where('counts.upload_pending', 0)
                ->has('buckets.under_review', 1));
    }

    /**
     * @return array{0: User, 1: GradeLevel, 2: Board}
     */
    private function seedAdminClass(): array
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
        SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return [$admin, $grade, $board];
    }

    /**
     * @return array{0: User, 1: SetAssignment}
     */
    private function seedStudentWrittenAssignment(): array
    {
        [$admin, $grade, $board] = $this->seedAdminClass();
        $year = AcademicYear::active();
        $syllabus = SyllabusVersion::query()->firstOrFail();
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $syllabus->id,
            'name' => 'Integers',
            'chapter_number' => '1',
            'sort_order' => 1,
        ]);
        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Addition',
            'sort_order' => 1,
        ]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Practice — Written',
            'set_number' => 1,
            'set_code' => 'T701-W',
            'tier' => 'starter',
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => WrittenSheetStatus::VERIFIED,
            'written_pdf_path' => 'written-sheets/1/test.pdf',
            'created_by' => $admin->id,
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
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'school_name' => 'School',
            'status' => StudentEnrollment::STATUS_ACTIVE,
        ]);

        $assignment = SetAssignment::query()->create([
            'worksheet_id' => $worksheet->id,
            'student_enrollment_id' => $enrollment->id,
            'assigned_by' => $admin->id,
            'status' => SetAssignment::STATUS_ASSIGNED,
            'assigned_at' => now(),
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        return [$user, $assignment];
    }
}
