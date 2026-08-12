<?php

namespace App\Services;

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

    public function start(Student $student, Worksheet $worksheet, User $user): SetAttempt
    {
        $pending = $this->correctionQueue->pendingForWorksheet($student->id, $worksheet->id);

        if ($pending->isEmpty()) {
            throw new \InvalidArgumentException('No wrong questions to redo for this set.');
        }

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            throw new \InvalidArgumentException('No active enrollment found.');
        }

        if ($worksheet->status !== Worksheet::STATUS_PUBLISHED) {
            throw new \InvalidArgumentException('This practice set is not available.');
        }

        $questionIds = $pending->pluck('question_id')->unique()->values()->all();
        $dueDate = now()->addDays(3)->toDateString();

        return DB::transaction(function () use ($student, $worksheet, $user, $enrollment, $questionIds, $dueDate) {
            $assignment = SetAssignment::query()
                ->where('student_enrollment_id', $enrollment->id)
                ->where('worksheet_id', $worksheet->id)
                ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
                ->orderByDesc('id')
                ->first();

            if (! $assignment) {
                $assignment = $this->assignmentService->assign(
                    $worksheet,
                    $enrollment,
                    $user,
                    $dueDate,
                    'Correction redo from study plan',
                );
            } elseif ($assignment->status === SetAssignment::STATUS_COMPLETED) {
                $assignment = $this->assignmentService->reassign(
                    $assignment,
                    $user,
                    $dueDate,
                    'Correction redo from study plan',
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
            }

            $nextNumber = ($assignment->attempts()->max('attempt_number') ?? 0) + 1;

            $attempt = SetAttempt::create([
                'set_assignment_id' => $assignment->id,
                'attempt_number' => $nextNumber,
                'mode' => SetAttempt::MODE_GUIDED,
                'started_at' => now(),
                'active_seconds' => 0,
                'active_session_started_at' => now(),
                'status' => SetAttempt::STATUS_IN_PROGRESS,
            ]);

            $this->guidedPractice->initializeForQuestionIds($attempt, $questionIds);

            if ($assignment->status === SetAssignment::STATUS_ASSIGNED) {
                $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);
            }

            return $attempt->fresh();
        });
    }
}
