<?php

namespace App\Support;

use App\Models\SetAssignment;
use App\Models\SetAttempt;
use Carbon\Carbon;

class AssignmentProgress
{
    public static function submissionTiming(SetAssignment $assignment, Carbon $completedAt): string
    {
        if (! $assignment->due_date) {
            return SetAttempt::TIMING_ON_TIME;
        }

        return $completedAt->toDateString() > $assignment->due_date->toDateString()
            ? SetAttempt::TIMING_LATE
            : SetAttempt::TIMING_ON_TIME;
    }

    public static function isOverdue(SetAssignment $assignment): bool
    {
        if (! $assignment->due_date) {
            return false;
        }

        if (in_array($assignment->status, [SetAssignment::STATUS_COMPLETED, SetAssignment::STATUS_CANCELLED], true)) {
            return false;
        }

        return now()->startOfDay()->gt($assignment->due_date);
    }

    public static function formatAssignmentSummary(SetAssignment $assignment, ?SetAttempt $latest): array
    {
        $overdue = self::isOverdue($assignment);

        return [
            'assignment_id' => $assignment->id,
            'practice_set_id' => $assignment->worksheet_id,
            'set_code' => $assignment->practiceSet->set_code,
            'set_number' => $assignment->practiceSet->set_number,
            'tier' => $assignment->practiceSet->tier,
            'tier_label' => $assignment->practiceSet->tier_label,
            'display_title' => $assignment->practiceSet->display_title,
            'topic_id' => $assignment->practiceSet->syllabus_topic_id,
            'topic_name' => $assignment->practiceSet->isChapterTest()
                ? 'Chapter test · '.$assignment->practiceSet->chapter?->name
                : ($assignment->practiceSet->isChapterPractice()
                    ? 'Chapter practice · '.$assignment->practiceSet->chapter?->name
                    : $assignment->practiceSet->topic?->name),
            'chapter_name' => $assignment->practiceSet->isChapterScope()
                ? $assignment->practiceSet->chapter?->name
                : $assignment->practiceSet->topic?->chapter?->name,
            'scope' => $assignment->practiceSet->scope ?? 'topic',
            'is_catch_up' => $assignment->practiceSet->isCatchUp(),
            'kind_label' => $assignment->practiceSet->isCatchUp()
                ? 'Catch-up'
                : ($assignment->practiceSet->isChapterTest() ? 'Test' : 'Practice'),
            'question_count' => $assignment->practiceSet->questions_count ?? $assignment->practiceSet->questions()->count(),
            'assignment_status' => $assignment->status,
            'target_date' => $assignment->due_date?->toDateString(),
            'assigned_at' => $assignment->assigned_at?->toDateTimeString(),
            'reassigned_at' => $assignment->reassigned_at?->toDateTimeString(),
            'is_overdue' => $overdue,
            'attempt_count' => $assignment->attempts->count(),
            'latest_score' => $latest?->score,
            'latest_max_score' => $latest?->max_score,
            'latest_score_percent' => ScoreLabel::percent($latest?->score, $latest?->max_score),
            'latest_score_label' => ScoreLabel::format($latest?->score, $latest?->max_score),
            'latest_time_seconds' => $latest?->time_seconds,
            'submitted_at' => $latest?->completed_at?->toDateTimeString(),
            'submission_timing' => $latest?->submission_timing,
            'status' => self::dashboardStatus($assignment, $latest, $overdue),
        ];
    }

    public static function dashboardStatus(SetAssignment $assignment, ?SetAttempt $latest, bool $overdue): string
    {
        if ($assignment->status === SetAssignment::STATUS_COMPLETED && $latest?->status === SetAttempt::STATUS_SUBMITTED) {
            return $latest->submission_timing === SetAttempt::TIMING_LATE ? 'green-late' : 'green';
        }

        if ($overdue) {
            return 'overdue';
        }

        if (in_array($assignment->status, [SetAssignment::STATUS_ASSIGNED, SetAssignment::STATUS_IN_PROGRESS], true)) {
            return 'yellow';
        }

        return 'grey';
    }

    /**
     * Student-facing summary for written homework assignments.
     */
    public static function formatWrittenStudentDashboardSummary(SetAssignment $assignment, ?\App\Models\WrittenSubmission $submission): array
    {
        $overdue = self::isOverdue($assignment);
        $practiceSet = $assignment->practiceSet;

        $status = 'yellow';
        $latestScore = null;
        $latestMaxScore = null;
        $latestScoreLabel = null;

        if ($submission?->status === \App\Models\WrittenSubmission::STATUS_GRADED) {
            $status = 'green';
            $latestScore = $submission->score;
            $latestMaxScore = $submission->max_score;
            $latestScoreLabel = ScoreLabel::format($submission->score, $submission->max_score);
        } elseif ($submission?->status === \App\Models\WrittenSubmission::STATUS_FAILED) {
            $status = 'overdue';
        } elseif ($overdue) {
            $status = 'overdue';
        } elseif ($submission && in_array($submission->status, [
            \App\Models\WrittenSubmission::STATUS_UPLOADED,
            \App\Models\WrittenSubmission::STATUS_PROCESSING,
        ], true)) {
            $status = 'yellow';
        }

        return [
            'assignment_id' => $assignment->id,
            'set_code' => $practiceSet->set_code,
            'set_number' => $practiceSet->set_number,
            'kind_label' => $practiceSet->isChapterTest() ? 'Written test' : 'Written practice',
            'delivery_mode' => 'written',
            'is_catch_up' => false,
            'scope' => $practiceSet->scope ?? 'topic',
            'target_date' => $assignment->due_date?->toDateString(),
            'is_overdue' => $overdue,
            'latest_score' => $latestScore,
            'latest_max_score' => $latestMaxScore,
            'latest_score_percent' => ScoreLabel::percent($latestScore, $latestMaxScore),
            'latest_score_label' => $latestScoreLabel,
            'submitted_at' => $submission?->graded_at?->toDateTimeString(),
            'latest_time_seconds' => null,
            'submission_timing' => null,
            'status' => $status,
            'latest_attempt_id' => null,
            'written_submission_status' => $submission?->status,
        ];
    }

    /**
     * Student-facing summary: set code and assignment status only (no syllabus / bank details).
     */
    public static function formatStudentDashboardSummary(SetAssignment $assignment, ?SetAttempt $latest): array
    {
        $summary = self::formatAssignmentSummary($assignment, $latest);

        return [
            'assignment_id' => $summary['assignment_id'],
            'set_code' => $summary['set_code'],
            'set_number' => $summary['set_number'],
            'kind_label' => $summary['kind_label'],
            'delivery_mode' => $assignment->practiceSet->delivery_mode ?? 'online',
            'is_catch_up' => $summary['is_catch_up'],
            'scope' => $summary['scope'],
            'target_date' => $summary['target_date'],
            'is_overdue' => $summary['is_overdue'],
            'latest_score' => $summary['latest_score'],
            'latest_max_score' => $summary['latest_max_score'],
            'latest_score_percent' => $summary['latest_score_percent'],
            'latest_score_label' => $summary['latest_score_label'],
            'submitted_at' => $summary['submitted_at'],
            'latest_time_seconds' => $summary['latest_time_seconds'],
            'submission_timing' => $summary['submission_timing'],
            'status' => $summary['status'],
            'latest_attempt_id' => $latest?->status === SetAttempt::STATUS_SUBMITTED ? $latest->id : null,
            'written_submission_status' => null,
        ];
    }
}
