<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\StudentEnrollment;
use App\Support\AssignmentProgress;
use App\Support\AttemptResultSummary;
use App\Support\DateLabels;
use App\Support\ProgressSummaryAnalytics;
use App\Support\ProgressSummaryChartSvg;
use App\Support\ProgressSummaryTable;
use App\Support\ScoreLabel;
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
                'practiceSet' => fn ($query) => $query
                    ->withCount('questions')
                    ->with(['chapter:id,name', 'topic.chapter:id,name']),
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
        $completed = $this->sortBySubmittedDateAsc($completed);
        $recentlyCompleted = $this->sortBySubmittedDateAsc($recentlyCompleted);
        $pending = $this->sortByTargetDateAsc($pending);
        $overdue = $this->sortByTargetDateAsc($overdue);
        $overall = ScoreLabel::aggregateFromRows($completed);
        $chapterPerformance = ProgressSummaryAnalytics::chapterPerformance($completed);
        $dateSource = ($periodStart && $recentlyCompleted !== []) ? $recentlyCompleted : $completed;
        $datePerformance = ProgressSummaryAnalytics::datePerformance($dateSource);

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
            'completed_by_chapter' => ProgressSummaryTable::groupByChapter($completed, 'submitted_at'),
            'pending' => $pending,
            'pending_by_chapter' => ProgressSummaryTable::groupByChapter($pending, 'target_date'),
            'overdue' => $overdue,
            'overdue_by_chapter' => ProgressSummaryTable::groupByChapter($overdue, 'target_date'),
            'recently_completed' => $recentlyCompleted,
            'recently_completed_by_chapter' => ProgressSummaryTable::groupByChapter($recentlyCompleted, 'submitted_at'),
            'help_requests' => $helpRequests,
            'stats' => [
                'completed_count' => count($completed),
                'pending_count' => count($pending),
                'overdue_count' => count($overdue),
                'help_count' => count($helpRequests),
                'recent_count' => count($recentlyCompleted),
                'overall_score_total' => $overall['score_total'],
                'overall_max_total' => $overall['max_total'],
                'overall_percent' => $overall['percent'],
                'overall_score_label' => $overall['label'],
            ],
            'chapter_performance' => $chapterPerformance,
            'date_performance' => $datePerformance,
            'charts' => [
                'chapter_bar_svg' => ProgressSummaryChartSvg::barChart(
                    collect($chapterPerformance)
                        ->map(fn (array $row) => [
                            'label' => $row['chapter_name'],
                            'percent' => $row['percent'],
                        ])
                        ->all(),
                ),
                'date_line_svg' => ProgressSummaryChartSvg::lineChart(
                    collect($datePerformance)
                        ->map(fn (array $row) => [
                            'label' => $row['date_label'],
                            'percent' => $row['percent'],
                        ])
                        ->all(),
                ),
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

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortBySubmittedDateAsc(array $rows): array
    {
        return collect($rows)
            ->sortBy(fn (array $row) => $row['submitted_at'] ?? '9999-12-31 23:59:59')
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortByTargetDateAsc(array $rows): array
    {
        return collect($rows)
            ->sortBy(fn (array $row) => $row['target_date'] ?? '9999-12-31')
            ->values()
            ->all();
    }
}
