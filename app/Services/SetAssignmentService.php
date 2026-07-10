<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\AssignmentProgress;
use App\Support\PracticeSetScope;
use Illuminate\Support\Collection;

class SetAssignmentService
{
    public function __construct(private ExamPlanService $examPlanService) {}

    public function assign(
        Worksheet $practiceSet,
        StudentEnrollment $enrollment,
        User $assigner,
        string $dueDate,
        ?string $notes = null,
        ?int $examPlanId = null,
    ): SetAssignment {
        if ($practiceSet->status !== Worksheet::STATUS_PUBLISHED) {
            throw new \InvalidArgumentException('Only published practice sets can be assigned.');
        }

        $existing = SetAssignment::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('worksheet_id', $practiceSet->id)
            ->whereNot('status', SetAssignment::STATUS_CANCELLED)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return $this->reassign($existing, $assigner, $dueDate, $notes);
        }

        $examPlan = $this->examPlanService->matchingPlanForAssignment(
            $enrollment,
            $practiceSet,
            $dueDate,
            $examPlanId,
        );

        return SetAssignment::create([
            'student_enrollment_id' => $enrollment->id,
            'worksheet_id' => $practiceSet->id,
            'exam_plan_id' => $examPlan?->id,
            'assigned_by' => $assigner->id,
            'assigned_at' => now(),
            'due_date' => $dueDate,
            'status' => SetAssignment::STATUS_ASSIGNED,
            'notes' => $notes,
        ]);
    }

    public function assignBulk(
        Worksheet $practiceSet,
        Collection $enrollments,
        User $assigner,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $assigned = 0;
        $skipped = 0;
        $errors = [];
        $assignedStudents = [];

        foreach ($enrollments as $enrollment) {
            try {
                $this->assign($practiceSet, $enrollment, $assigner, $dueDate, $notes);
                $assigned++;
                $assignedStudents[] = $enrollment->student;
            } catch (\InvalidArgumentException $e) {
                $skipped++;
                $errors[] = "{$enrollment->student->name}: {$e->getMessage()}";
            }
        }

        return compact('assigned', 'skipped', 'errors', 'assignedStudents');
    }

    public function assignToActiveYearClass(
        Worksheet $practiceSet,
        User $assigner,
        string $dueDate,
        ?int $gradeLevelId = null,
        ?string $notes = null,
        ?int $boardId = null,
    ): array {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            throw new \InvalidArgumentException('No active academic year.');
        }

        $query = StudentEnrollment::query()
            ->with(['student:id,name,email,user_id', 'student.user:id,email'])
            ->where('academic_year_id', $activeYear->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE);

        if ($gradeLevelId) {
            $query->where('grade_level_id', $gradeLevelId);
        }

        if ($boardId) {
            $query->where('board_id', $boardId);
        }

        return $this->assignBulk($practiceSet, $query->get(), $assigner, $dueDate, $notes);
    }

    public function reassign(
        SetAssignment $assignment,
        User $assigner,
        string $dueDate,
        ?string $notes = null,
    ): SetAssignment {
        if ($assignment->status === SetAssignment::STATUS_CANCELLED) {
            throw new \InvalidArgumentException('This assignment was cancelled.');
        }

        $inProgress = $assignment->attempts()->where('status', 'in_progress')->exists();
        if ($inProgress) {
            $assignment->attempts()->where('status', 'in_progress')->delete();
        }

        $assignment->update([
            'status' => SetAssignment::STATUS_ASSIGNED,
            'due_date' => $dueDate,
            'notes' => $notes ?? $assignment->notes,
            'assigned_by' => $assigner->id,
            'reassigned_at' => now(),
        ]);

        return $assignment->fresh();
    }

    public function studentProgressForTopic(int $enrollmentId, int $topicId): Collection
    {
        return SetAssignment::query()
            ->with([
                'practiceSet' => fn ($q) => $q->withCount('questions'),
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            ])
            ->where('student_enrollment_id', $enrollmentId)
            ->whereHas('practiceSet', fn ($q) => $q->where('syllabus_topic_id', $topicId))
            ->get()
            ->map(function (SetAssignment $assignment) {
                $latest = $assignment->attempts->first();

                return AssignmentProgress::formatAssignmentSummary($assignment, $latest);
            })
            ->sortBy(fn (array $row) => $row['set_number'])
            ->values();
    }

    public function studentProgressForChapter(int $enrollmentId, int $chapterId): Collection
    {
        return SetAssignment::query()
            ->with([
                'practiceSet' => fn ($q) => $q->withCount('questions')->with('chapter'),
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            ])
            ->where('student_enrollment_id', $enrollmentId)
            ->whereHas('practiceSet', fn ($q) => $q
                ->where('scope', PracticeSetScope::CHAPTER)
                ->where('syllabus_chapter_id', $chapterId))
            ->get()
            ->map(function (SetAssignment $assignment) {
                $latest = $assignment->attempts->first();

                return AssignmentProgress::formatAssignmentSummary($assignment, $latest);
            })
            ->sortBy(fn (array $row) => $row['set_number'])
            ->values();
    }

    /**
     * @param  list<int>  $studentIds
     * @return array{assigned: int, skipped: int, errors: list<string>, assignedStudents: list<Student>}
     */
    public function assignToStudents(
        Worksheet $practiceSet,
        array $studentIds,
        User $assigner,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            throw new \InvalidArgumentException('No active academic year.');
        }

        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));

        if ($studentIds === []) {
            throw new \InvalidArgumentException('Select at least one student.');
        }

        $enrollments = StudentEnrollment::query()
            ->with(['student:id,name,email,user_id', 'student.user:id,email'])
            ->where('academic_year_id', $activeYear->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->whereIn('student_id', $studentIds)
            ->get();

        if ($enrollments->isEmpty()) {
            throw new \InvalidArgumentException('No active enrollments found for the selected students.');
        }

        return $this->assignBulk($practiceSet, $enrollments, $assigner, $dueDate, $notes);
    }

    /**
     * @return Collection<int, array{
     *     student_id: int,
     *     student_name: string,
     *     class_name: string,
     *     grade_level_id: ?int,
     *     assignment_id: int,
     *     assignment_status: string,
     *     target_date: ?string,
     *     latest_score: ?int,
     *     latest_max_score: ?int,
     *     submitted_at: ?string,
     *     submission_timing: ?string,
     *     attempt_count: int,
     *     is_overdue: bool
     * }>
     */
    public function worksheetAssignmentOverview(int $worksheetId, ?int $academicYearId = null): Collection
    {
        $yearId = $academicYearId ?? AcademicYear::active()?->id;

        if (! $yearId) {
            return collect();
        }

        return SetAssignment::query()
            ->with([
                'enrollment.student:id,name',
                'enrollment.gradeLevel:id,name',
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            ])
            ->where('worksheet_id', $worksheetId)
            ->whereNot('status', SetAssignment::STATUS_CANCELLED)
            ->whereHas('enrollment', fn ($q) => $q->where('academic_year_id', $yearId))
            ->get()
            ->map(function (SetAssignment $assignment) {
                $latest = $assignment->attempts->first();
                $summary = AssignmentProgress::formatAssignmentSummary($assignment, $latest);

                return [
                    'student_id' => $assignment->enrollment->student->id,
                    'student_name' => $assignment->enrollment->student->name,
                    'class_name' => $assignment->enrollment->gradeLevel?->name ?? '—',
                    'grade_level_id' => $assignment->enrollment->grade_level_id,
                    'assignment_id' => $assignment->id,
                    'assignment_status' => $summary['assignment_status'],
                    'target_date' => $summary['target_date'],
                    'latest_score' => $summary['latest_score'],
                    'latest_max_score' => $summary['latest_max_score'],
                    'submitted_at' => $summary['submitted_at'],
                    'submission_timing' => $summary['submission_timing'],
                    'attempt_count' => $summary['attempt_count'],
                    'is_overdue' => $summary['is_overdue'],
                ];
            })
            ->sortBy(fn (array $row) => "{$row['class_name']}|{$row['student_name']}")
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     name: string,
     *     class_name: string,
     *     grade_level_id: ?int,
     *     board_code: ?string,
     *     label: string
     * }>
     */
    public function activeStudentsForAssignment(
        ?int $academicYearId = null,
        ?int $gradeLevelId = null,
        ?int $boardId = null,
    ): Collection {
        $yearId = $academicYearId ?? AcademicYear::active()?->id;

        if (! $yearId) {
            return collect();
        }

        return Student::query()
            ->whereHas('enrollments', fn ($q) => $q
                ->where('academic_year_id', $yearId)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->when($gradeLevelId, fn ($query) => $query->where('grade_level_id', $gradeLevelId))
                ->when($boardId, fn ($query) => $query->where('board_id', $boardId)))
            ->with(['enrollments' => fn ($q) => $q
                ->where('academic_year_id', $yearId)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->when($gradeLevelId, fn ($query) => $query->where('grade_level_id', $gradeLevelId))
                ->when($boardId, fn ($query) => $query->where('board_id', $boardId))
                ->with(['gradeLevel:id,name,sort_order', 'board:id,code'])])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Student $student) {
                $enrollment = $student->enrollments->first();
                $className = $enrollment?->gradeLevel?->name ?? '—';

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'class_name' => $className,
                    'grade_level_id' => $enrollment?->grade_level_id,
                    'board_code' => $enrollment?->board?->code,
                    'label' => "{$student->name} ({$className})",
                ];
            })
            ->values();
    }
}
