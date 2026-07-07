<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\StudentEnrollment;
use App\Support\AssignmentProgress;
use App\Support\AttemptResultSummary;
use App\Support\DateLabels;
use Carbon\Carbon;

class StudentProgressSummaryService
{
    public function __construct(
        private QuestionResolutionService $resolutionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        StudentEnrollment $enrollment,
        ?Carbon $asOf = null,
        ?Carbon $periodStart = null,
    ): array {
        $asOf = ($asOf ?? now())->copy()->endOfDay();
        $periodStart = $periodStart?->copy()->startOfDay();

        $enrollment->loadMissing('student:id,name', 'gradeLevel:id,name');

        $assignments = SetAssignment::query()
            ->with([
                'practiceSet' => fn ($query) => $query->withCount('questions'),
                'attempts' => fn ($query) => $query->orderByDesc('attempt_number'),
            ])
            ->where('student_enrollment_id', $enrollment->id)
            ->where('assigned_at', '<=', $asOf)
            ->whereHas('practiceSet', fn ($query) => $query->where('status', 'published'))
            ->get()
            ->sortBy([
                ['practiceSet.set_code', 'asc'],
                ['practiceSet.set_number', 'asc'],
            ])
            ->values();

        $completed = [];
        $pending = [];
        $overdue = [];
        $recentlyCompleted = [];

        foreach ($assignments as $assignment) {
            $latest = $this->latestSubmittedAttemptAsOf($assignment, $asOf);
            $row = AssignmentProgress::formatAssignmentSummary($assignment, $latest);
            $row['review_items'] = $latest
                ? $this->reviewItemsForAttempt($latest)
                : [];
            $row['latest_attempt_number'] = $latest?->attempt_number;

            $isCompleted = $latest !== null;

            if ($isCompleted) {
                $completed[] = $row;

                if ($periodStart && $latest->completed_at?->between($periodStart, $asOf)) {
                    $recentlyCompleted[] = $row;
                }

                continue;
            }

            if ($assignment->due_date && $assignment->due_date->lt($asOf->copy()->startOfDay())) {
                $overdue[] = $row;

                continue;
            }

            $pending[] = $row;
        }

        $helpRequests = $this->resolutionService->pendingForEnrollment($enrollment->id);

        return [
            'student_name' => $enrollment->student?->name ?? 'Student',
            'class_name' => $enrollment->gradeLevel?->name,
            'as_of_date' => $asOf->toDateString(),
            'as_of_label' => DateLabels::formatDate($asOf->toDateString()),
            'period_start' => $periodStart?->toDateString(),
            'period_label' => $periodStart
                ? DateLabels::formatDate($periodStart->toDateString()).' – '.DateLabels::formatDate($asOf->toDateString())
                : null,
            'completed' => $completed,
            'pending' => $pending,
            'overdue' => $overdue,
            'recently_completed' => $recentlyCompleted,
            'help_requests' => $helpRequests,
            'stats' => [
                'completed_count' => count($completed),
                'pending_count' => count($pending),
                'overdue_count' => count($overdue),
                'help_count' => count($helpRequests),
                'recent_count' => count($recentlyCompleted),
            ],
            'dashboard_url' => route('dashboard'),
        ];
    }

    private function latestSubmittedAttemptAsOf(SetAssignment $assignment, Carbon $asOf): ?SetAttempt
    {
        return $assignment->attempts
            ->first(function (SetAttempt $attempt) use ($asOf) {
                return $attempt->status === SetAttempt::STATUS_SUBMITTED
                    && $attempt->completed_at
                    && $attempt->completed_at->lte($asOf);
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewItemsForAttempt(SetAttempt $attempt): array
    {
        $attempt->loadMissing([
            'answers.question.topic.chapter',
            'guidedQuestions.question.topic.chapter',
            'assignment.practiceSet.questions.topic.chapter',
        ]);

        $summary = AttemptResultSummary::forAdmin($attempt);

        return array_map(function (array $question) {
            $label = "Q{$question['number']} — {$question['outcome_label']}";

            if ($question['topic_name'] ?? null) {
                $label .= " · {$question['topic_name']}";
            } elseif ($question['chapter_name'] ?? null) {
                $label .= " · {$question['chapter_name']}";
            }

            return [
                'label' => $label,
                'help_asked_label' => $question['help_asked_label'] ?? null,
            ];
        }, $summary['wrong_questions']);
    }
}
