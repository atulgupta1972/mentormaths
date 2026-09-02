<?php

namespace App\Services;

use App\Models\AssignmentSumInstance;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Support\Facades\DB;

class PracticeCorrectionPracticeService
{
    public function __construct(
        private PracticeCorrectionQueueService $correctionQueue,
        private SetAssignmentService $assignmentService,
        private GuidedPracticeService $guidedPractice,
    ) {}

    public function start(
        Student $student,
        Worksheet $worksheet,
        User $user,
        ?int $assignmentId = null,
    ): SetAttempt {
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            throw new \InvalidArgumentException('No active enrollment found.');
        }

        if ($worksheet->status !== Worksheet::STATUS_PUBLISHED) {
            throw new \InvalidArgumentException('This practice set is not available.');
        }

        $assignment = $this->resolveAssignment($enrollment->id, $worksheet->id, $assignmentId);

        $questionIds = $this->pendingQuestionIds($student->id, $worksheet->id, $assignment);

        if ($questionIds === []) {
            throw new \InvalidArgumentException('No remaining questions to work on for this set.');
        }

        $dueDate = now()->addDays(3)->toDateString();

        return DB::transaction(function () use ($worksheet, $user, $enrollment, $questionIds, $dueDate, $assignment) {
            if (! $assignment) {
                $assignment = $this->assignmentService->assign(
                    $worksheet,
                    $enrollment,
                    $user,
                    $dueDate,
                    'Correction from study plan',
                );
            } else {
                $inProgress = $assignment->attempts()
                    ->where('status', SetAttempt::STATUS_IN_PROGRESS)
                    ->exists();

                if ($inProgress) {
                    $assignment->attempts()
                        ->where('status', SetAttempt::STATUS_IN_PROGRESS)
                        ->delete();
                }

                // Keep the same assignment (and its Total Pool) — do not reassign revisions/originals.
                if ($assignment->status === SetAssignment::STATUS_COMPLETED) {
                    $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);
                }
            }

            $nextNumber = ($assignment->attempts()->max('attempt_number') ?? 0) + 1;

            $attempt = SetAttempt::create([
                'set_assignment_id' => $assignment->id,
                'attempt_number' => $nextNumber,
                'mode' => SetAttempt::MODE_GUIDED,
                'is_correction_practice' => true,
                'started_at' => now(),
                'active_seconds' => 0,
                'active_session_started_at' => now(),
                'status' => SetAttempt::STATUS_IN_PROGRESS,
            ]);

            $this->guidedPractice->initializeForQuestionIds($attempt, $questionIds);

            if ($assignment->status === SetAssignment::STATUS_ASSIGNED) {
                $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);
            }

            app(AssignmentPoolScore::class)->ensureOriginals(
                $assignment->fresh(['enrollment', 'practiceSet.questions']),
            );

            return $attempt->fresh();
        });
    }

    private function resolveAssignment(int $enrollmentId, int $worksheetId, ?int $assignmentId): ?SetAssignment
    {
        if ($assignmentId) {
            $assignment = SetAssignment::query()
                ->whereKey($assignmentId)
                ->where('student_enrollment_id', $enrollmentId)
                ->where('worksheet_id', $worksheetId)
                ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
                ->first();

            if ($assignment) {
                return $assignment;
            }
        }

        // Prefer an assignment that still has pending pool remediations.
        $withPending = SetAssignment::query()
            ->where('student_enrollment_id', $enrollmentId)
            ->where('worksheet_id', $worksheetId)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->whereHas('sumInstances', fn ($q) => $q->where('status', AssignmentSumInstance::STATUS_PENDING))
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->first();

        if ($withPending) {
            return $withPending;
        }

        return SetAssignment::query()
            ->where('student_enrollment_id', $enrollmentId)
            ->where('worksheet_id', $worksheetId)
            ->where('revision_number', 0)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return list<int>
     */
    private function pendingQuestionIds(int $studentId, int $worksheetId, ?SetAssignment $assignment): array
    {
        if ($assignment) {
            $fromPool = AssignmentSumInstance::query()
                ->where('set_assignment_id', $assignment->id)
                ->where('status', AssignmentSumInstance::STATUS_PENDING)
                ->orderBy('generation')
                ->orderBy('id')
                ->pluck('question_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($fromPool !== []) {
                return $fromPool;
            }
        }

        return $this->correctionQueue
            ->pendingForWorksheet($studentId, $worksheetId)
            ->pluck('question_id')
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
