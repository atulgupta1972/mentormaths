<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetAssignmentShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_written_assignment_show_redirects_to_written_sheet_with_student_and_assignment(): void
    {
        [$assignment, $admin, $worksheet, $student] = $this->seedWrittenAssignment();

        $this->actingAs($admin)
            ->get(route('admin.set-assignments.show', $assignment))
            ->assertRedirect(route('admin.written-sheets.show', [
                'worksheet' => $worksheet->id,
                'student_id' => $student->id,
                'assignment_id' => $assignment->id,
            ]));
    }

    public function test_written_sheet_show_lists_assignments_even_when_pending_review(): void
    {
        [$assignment, $admin, $worksheet] = $this->seedWrittenAssignment();
        $worksheet->update(['written_status' => WrittenSheetStatus::PENDING_REVIEW]);

        $this->actingAs($admin)
            ->get(route('admin.written-sheets.show', $worksheet))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/WrittenSheets/Show')
                ->has('assignments', 1)
                ->where('assignments.0.assignment_id', $assignment->id));
    }

    /**
     * @return array{0: SetAssignment, 1: User, 2: Worksheet, 3: Student}
     */
    private function seedWrittenAssignment(): array
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
            'chapter_number' => 12,
            'sort_order' => 12,
        ]);

        $topic = SyllabusTopic::query()->create([
            'syllabus_chapter_id' => $chapter->id,
            'name' => 'Chapter test',
            'sort_order' => 1,
        ]);

        $question = Question::query()->create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => 'What is 2 + 2?',
            'source' => Question::SOURCE_MANUAL,
        ]);

        QuestionBlankAnswer::query()->create([
            'question_id' => $question->id,
            'correct_answer' => '4',
            'answer_format' => 'integer',
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $worksheet = Worksheet::query()->create([
            'title' => 'Chapter test — Written',
            'set_number' => 3,
            'set_code' => 'T7122-W',
            'tier' => 'test',
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => WrittenSheetStatus::VERIFIED,
            'written_pdf_path' => 'written-sheets/1/test.pdf',
            'created_by' => $admin->id,
        ]);

        $worksheet->questions()->attach($question->id, ['sort_order' => 1]);

        $student = Student::query()->create([
            'user_id' => User::factory()->create(['role' => User::ROLE_STUDENT])->id,
            'name' => 'Ananya Arora',
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
            'assigned_at' => now(),
            'due_date' => now()->addDays(3),
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        return [$assignment, $admin, $worksheet, $student];
    }
}
