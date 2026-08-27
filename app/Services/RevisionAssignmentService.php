<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revision mentoring: after an original sheet is fully corrected (completion 100%,
 * no pending remediations), auto-open Rev 1. Score% may be below 100 — original
 * wrongs stay in the pool forever.
 * Further revisions (R2, R3…) are started by student/teacher on demand.
 */
class RevisionAssignmentService
{
    public function __construct(
        private AssignmentPoolScore $poolScore,
    ) {}

    /**
     * If this assignment is an original that is fully corrected, ensure Rev 1 exists (due today).
     */
    public function ensureFirstRevisionIfReady(SetAssignment $assignment): ?SetAssignment
    {
        $assignment->refresh();

        if ($assignment->isRevision()) {
            return null;
        }

        // Rebuild from attempts so older completions (pre-pool) unlock correctly.
        $this->poolScore->rebuildFromAttempts($assignment);

        if (! $this->poolScore->isFullyCorrected($assignment)) {
            return null;
        }

        return $this->ensureRevisionNumber($assignment, 1, auto: true);
    }

    /**
     * Start the next revision (R2, R3…) from any completed revision or from the original when ready.
     */
    public function startNextRevision(SetAssignment $fromAssignment, ?User $actor = null): SetAssignment
    {
        $root = $this->rootOriginal($fromAssignment);

        $this->poolScore->rebuildFromAttempts($root);

        if (! $this->poolScore->isFullyCorrected($root)) {
            throw new \InvalidArgumentException('Finish and correct the original sheet before starting revision.');
        }

        $open = $this->openRevisionForRoot($root);
        if ($open) {
            return $open;
        }

        $nextNumber = $this->latestRevisionNumber($root) + 1;
        if ($nextNumber < 1) {
            $nextNumber = 1;
        }

        return $this->ensureRevisionNumber($root, $nextNumber, auto: false, actor: $actor);
    }

    public function ensureRevisionNumber(
        SetAssignment $original,
        int $revisionNumber,
        bool $auto = false,
        ?User $actor = null,
    ): SetAssignment {
        if ($revisionNumber < 1) {
            throw new \InvalidArgumentException('Revision number must be at least 1.');
        }

        $original = $this->rootOriginal($original);

        $existing = SetAssignment::query()
            ->where('student_enrollment_id', $original->student_enrollment_id)
            ->where('worksheet_id', $original->worksheet_id)
            ->where('revision_number', $revisionNumber)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Only one open revision at a time.
        $open = $this->openRevisionForRoot($original);
        if ($open) {
            return $open;
        }

        return DB::transaction(function () use ($original, $revisionNumber, $auto, $actor) {
            $assignment = SetAssignment::query()->create([
                'student_enrollment_id' => $original->student_enrollment_id,
                'worksheet_id' => $original->worksheet_id,
                'parent_assignment_id' => $original->id,
                'revision_number' => $revisionNumber,
                'exam_plan_id' => $original->exam_plan_id,
                'effective_syllabus_chapter_id' => $original->effective_syllabus_chapter_id,
                'assigned_by' => $actor?->id ?? $original->assigned_by,
                'assigned_at' => now(),
                'due_date' => now()->toDateString(),
                'status' => SetAssignment::STATUS_ASSIGNED,
                'notes' => $auto
                    ? 'Auto revision after 100% score'
                    : 'Revision mentoring',
            ]);

            $this->poolScore->ensureOriginals(
                $assignment->fresh(['enrollment', 'practiceSet.questions']),
            );

            return $assignment;
        });
    }

    public function rootOriginal(SetAssignment $assignment): SetAssignment
    {
        if (! $assignment->isRevision()) {
            return $assignment;
        }

        if ($assignment->parent_assignment_id) {
            $parent = SetAssignment::query()->find($assignment->parent_assignment_id);
            if ($parent) {
                return $this->rootOriginal($parent);
            }
        }

        $root = SetAssignment::query()
            ->where('student_enrollment_id', $assignment->student_enrollment_id)
            ->where('worksheet_id', $assignment->worksheet_id)
            ->where('revision_number', 0)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->orderBy('id')
            ->first();

        return $root ?? $assignment;
    }

    public function openRevisionForRoot(SetAssignment $original): ?SetAssignment
    {
        return SetAssignment::query()
            ->where('student_enrollment_id', $original->student_enrollment_id)
            ->where('worksheet_id', $original->worksheet_id)
            ->where('revision_number', '>', 0)
            ->whereIn('status', [SetAssignment::STATUS_ASSIGNED, SetAssignment::STATUS_IN_PROGRESS])
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->orderByDesc('revision_number')
            ->orderByDesc('id')
            ->first();
    }

    public function latestRevisionNumber(SetAssignment $original): int
    {
        return (int) SetAssignment::query()
            ->where('student_enrollment_id', $original->student_enrollment_id)
            ->where('worksheet_id', $original->worksheet_id)
            ->where('revision_number', '>', 0)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->max('revision_number');
    }

    /**
     * @return list<SetAssignment>
     */
    public function revisionsForOriginal(SetAssignment $original): array
    {
        return SetAssignment::query()
            ->where('student_enrollment_id', $original->student_enrollment_id)
            ->where('worksheet_id', $original->worksheet_id)
            ->where('revision_number', '>', 0)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->orderBy('revision_number')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
