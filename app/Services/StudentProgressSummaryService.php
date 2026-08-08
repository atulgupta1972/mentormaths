<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\StudentEnrollment;
use App\Models\WrittenSubmission;
use App\Support\AssignmentProgress;
use App\Support\AttemptResultSummary;
use App\Support\DateLabels;
use App\Support\ProgressSummaryAnalytics;
use App\Support\ProgressSummaryChartImage;
use App\Support\ProgressSummaryTable;
use App\Support\ScoreLabel;
use App\Support\StudentEngagementMetrics;
use App\Support\WorksheetDeliveryMode;
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
        ?string $mentorRemark = null,
    ): array {
        $asOf = ($asOf ?? now())->copy()->endOfDay();
        $periodStart = $periodStart?->copy()->startOfDay();

        $enrollment->loadMissing(['student.user', 'gradeLevel:id,name']);

        $assignments = SetAssignment::query()
            ->with([
                'practiceSet' => fn ($query) => $query
                    ->withCount('questions')
                    ->with(['chapter:id,name', 'topic.chapter:id,name']),
                'attempts' => fn ($query) => $query->orderByDesc('attempt_number'),
                'writtenSubmissions' => fn ($query) => $query->orderByDesc('id'),
            ])
            ->where('student_enrollment_id', $enrollment->id)
            ->whereNot('status', SetAssignment::STATUS_CANCELLED)
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
            if ($assignment->practiceSet?->delivery_mode === WorksheetDeliveryMode::WRITTEN) {
                $submission = $this->latestGradedWrittenAsOf($assignment, $asOf);
                $row = AssignmentProgress::formatWrittenAssignmentSummary($assignment, $submission);
                $row['review_items'] = $this->reviewItemsForWritten($submission);
                $row['latest_attempt_number'] = null;

                $isCompleted = $submission !== null;

                if ($isCompleted) {
                    $completed[] = $row;

                    if ($periodStart && $submission->graded_at?->between($periodStart, $asOf)) {
                        $recentlyCompleted[] = $row;
                    }

                    continue;
                }

                if ($assignment->due_date && $assignment->due_date->lt($asOf->copy()->startOfDay())) {
                    $overdue[] = $row;

                    continue;
                }

                $pending[] = $row;

                continue;
            }

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

        $engagementFrom = $periodStart ?? $asOf->copy()->startOfDay();
        $engagement = StudentEngagementMetrics::forEnrollment($enrollment, $engagementFrom, $asOf);
        $remark = is_string($mentorRemark) ? trim($mentorRemark) : '';
        $remark = $remark !== '' ? $remark : null;

        return [
            'student_name' => $enrollment->student?->name ?? 'Student',
            'class_name' => $enrollment->gradeLevel?->name,
            'as_of_date' => $asOf->toDateString(),
            'as_of_label' => DateLabels::formatDate($asOf->toDateString()),
            'date_from' => $engagement['date_from'],
            'date_to' => $engagement['date_to'],
            'period_start' => $periodStart?->toDateString() ?? $engagement['date_from'],
            'period_label' => DateLabels::formatDate($engagement['date_from']).' – '.DateLabels::formatDate($engagement['date_to']),
            'mentor_remark' => $remark,
            'engagement' => $engagement,
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
                'time_spent_seconds' => $engagement['time_spent_seconds'],
                'time_spent_label' => $engagement['time_spent_label'],
                'total_days' => $engagement['total_days'],
                'days_logged_in' => $engagement['days_logged_in'],
                'days_not_logged_in' => $engagement['days_not_logged_in'],
            ],
            'chapter_performance' => $chapterPerformance,
            'date_performance' => $datePerformance,
            'charts' => [
                'chapter_bar_chart' => ProgressSummaryChartImage::barChartDataUri(
                    collect($chapterPerformance)
                        ->map(fn (array $row) => [
                            'label' => $row['chapter_name'],
                            'percent' => $row['percent'],
                        ])
                        ->all(),
                ),
                'date_line_chart' => ProgressSummaryChartImage::lineChartDataUri(
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

    /**
     * Daily reminder payload: only balance work still to do (practice, test, written, corrections).
     *
     * @return array<string, mixed>
     */
    public function buildBalanceReminder(StudentEnrollment $enrollment, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->endOfDay();
        $full = $this->build($enrollment, $asOf);

        $pending = collect($full['pending'])
            ->reject(fn (array $row) => $this->isWrittenAwaitingGrade($row))
            ->values()
            ->all();

        $overdue = collect($full['overdue'])
            ->reject(fn (array $row) => $this->isWrittenAwaitingGrade($row))
            ->values()
            ->all();

        $pending = $this->enrichBalanceRows($pending, $asOf);
        $overdue = $this->enrichBalanceRows($overdue, $asOf);

        $helpRequests = array_map(function (array $item) use ($asOf) {
            if (! empty($item['gave_up_at'])) {
                $days = (int) Carbon::parse(substr((string) $item['gave_up_at'], 0, 10))
                    ->startOfDay()
                    ->diffInDays($asOf->copy()->startOfDay());

                $item['pending_days_label'] = $days === 0
                    ? 'Asked today'
                    : ($days === 1 ? 'Waiting 1 day' : "Waiting {$days} days");
            } else {
                $item['pending_days_label'] = '—';
            }

            return $item;
        }, $full['help_requests']);
        $assignmentRows = array_merge($overdue, $pending);
        $workTypes = $this->countByWorkType($assignmentRows);

        return [
            'student_name' => $full['student_name'],
            'class_name' => $full['class_name'],
            'as_of_date' => $full['as_of_date'],
            'as_of_label' => $full['as_of_label'],
            'pending' => $pending,
            'pending_by_chapter' => ProgressSummaryTable::groupByChapter($pending, 'target_date'),
            'overdue' => $overdue,
            'overdue_by_chapter' => ProgressSummaryTable::groupByChapter($overdue, 'target_date'),
            'help_requests' => $helpRequests,
            'stats' => [
                'pending_count' => count($pending),
                'overdue_count' => count($overdue),
                'help_count' => count($helpRequests),
                'balance_count' => count($pending) + count($overdue) + count($helpRequests),
                'practice_count' => $workTypes['practice'],
                'test_count' => $workTypes['test'],
                'written_count' => $workTypes['written'],
                'correction_count' => count($helpRequests),
            ],
            'dashboard_url' => $full['dashboard_url'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function enrichBalanceRows(array $rows, Carbon $asOf): array
    {
        return array_map(function (array $row) use ($asOf) {
            return array_merge($row, ProgressSummaryTable::pendingDaysMeta($row, $asOf));
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isWrittenAwaitingGrade(array $row): bool
    {
        if (($row['delivery_mode'] ?? '') !== WorksheetDeliveryMode::WRITTEN) {
            return false;
        }

        $status = $row['written_submission_status'] ?? null;

        return in_array($status, [
            WrittenSubmission::STATUS_UPLOADED,
            WrittenSubmission::STATUS_PROCESSING,
            WrittenSubmission::STATUS_GRADED,
        ], true);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{practice: int, test: int, written: int}
     */
    private function countByWorkType(array $rows): array
    {
        $practice = 0;
        $test = 0;
        $written = 0;

        foreach ($rows as $row) {
            if (($row['delivery_mode'] ?? '') === WorksheetDeliveryMode::WRITTEN) {
                $written++;

                continue;
            }

            $kind = strtolower((string) ($row['kind_label'] ?? ''));

            if (str_contains($kind, 'test')) {
                $test++;

                continue;
            }

            $practice++;
        }

        return [
            'practice' => $practice,
            'test' => $test,
            'written' => $written,
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

    private function latestGradedWrittenAsOf(SetAssignment $assignment, Carbon $asOf): ?WrittenSubmission
    {
        return $assignment->writtenSubmissions
            ->first(function (WrittenSubmission $submission) use ($asOf) {
                return $submission->status === WrittenSubmission::STATUS_GRADED
                    && $submission->graded_at
                    && $submission->graded_at->lte($asOf);
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewItemsForWritten(?WrittenSubmission $submission): array
    {
        $items = [];

        if ($submission?->handwriting_rating) {
            $items[] = [
                'label' => 'Handwriting — '.($submission->handwritingLabel() ?? $submission->handwriting_rating),
                'help_asked_label' => null,
            ];
        }

        $remarks = trim((string) ($submission?->teacher_remarks ?? ''));
        if ($remarks !== '') {
            $items[] = [
                'label' => 'Teacher remarks — '.$remarks,
                'help_asked_label' => null,
            ];
        } else {
            $feedback = trim((string) ($submission?->ai_summary ?? ''));
            if ($feedback !== '') {
                $items[] = [
                    'label' => 'Teacher feedback — '.$feedback,
                    'help_asked_label' => null,
                ];
            }
        }

        return $items;
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
