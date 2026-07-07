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
            ->whereIn('status', [
                SetAssignment::STATUS_ASSIGNED,
                SetAssignment::STATUS_IN_PROGRESS,
            ])
            ->first();

        if ($existing) {
            throw new \InvalidArgumentException('This student already has an active assignment for this set.');
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
            throw new \InvalidArgumentException('Student has an attempt in progress. Wait for submission first.');
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
     * @return Collection<int, array{id: int, name: string, class_name: string, label: string}>
     */
    public function activeStudentsForAssignment(?int $academicYearId = null): Collection
    {
        $yearId = $academicYearId ?? AcademicYear::active()?->id;

        if (! $yearId) {
            return collect();
        }

        return Student::query()
            ->whereHas('enrollments', fn ($q) => $q
                ->where('academic_year_id', $yearId)
                ->where('status', StudentEnrollment::STATUS_ACTIVE))
            ->with(['enrollments' => fn ($q) => $q
                ->where('academic_year_id', $yearId)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->with('gradeLevel:id,name,sort_order')])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Student $student) {
                $className = $student->enrollments->first()?->gradeLevel?->name ?? '—';

                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'class_name' => $className,
                    'label' => "{$student->name} ({$className})",
                ];
            })
            ->values();
    }
}
