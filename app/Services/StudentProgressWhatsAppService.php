<?php

namespace App\Services;

use App\Models\Student;
use App\Support\ProgressSummaryTable;

class StudentProgressWhatsAppService
{
    public function __construct(
        private StudentNotificationContactService $contactService,
    ) {}

    /**
     * @return list<array{mobile: string, label: string, message: string}>
     */
    public function notificationsForSummary(Student $student, array $summary): array
    {
        $message = $this->buildMessage($summary);
        $recipients = $this->contactService->recipientsForStudent($student);

        return array_map(fn (array $recipient) => [
            'mobile' => $recipient['mobile'],
            'label' => $recipient['label'],
            'message' => $message,
            'student_id' => $student->id,
            'dashboard_url' => $summary['dashboard_url'] ?? null,
        ], $recipients);
    }

    /**
     * @return list<array{mobile: string, label: string, message: string}>
     */
    public function notificationsForBalanceReminder(Student $student, array $summary): array
    {
        $message = $this->buildBalanceMessage($summary);
        $recipients = $this->contactService->recipientsForStudent($student);

        return array_map(fn (array $recipient) => [
            'mobile' => $recipient['mobile'],
            'label' => $recipient['label'],
            'message' => $message,
            'student_id' => $student->id,
            'dashboard_url' => $summary['dashboard_url'] ?? null,
        ], $recipients);
    }

    /**
     * @return list<array{mobile: string, label: string, message: string, student_id: int, dashboard_url: ?string}>
     */
    public function notificationsForStudyPlanStatus(Student $student, array $summary): array
    {
        $message = $this->buildStudyPlanMessage($summary);
        $recipients = $this->contactService->recipientsForStudent($student);

        return array_map(fn (array $recipient) => [
            'mobile' => $recipient['mobile'],
            'label' => $recipient['label'],
            'message' => $message,
            'student_id' => $student->id,
            'dashboard_url' => $summary['dashboard_url'] ?? null,
        ], $recipients);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildStudyPlanMessage(array $summary): string
    {
        $perf = $summary['study_plan'] ?? [];
        $lines = [
            'Hello, this is Mentor Maths.',
            '',
            "Study plan status for {$summary['student_name']}",
            'As of: '.($summary['as_of_label'] ?? ''),
        ];

        if ($summary['class_name'] ?? null) {
            $lines[] = 'Class: '.$summary['class_name'];
        }

        $lines[] = '';
        $lines[] = 'Chapters in study plan: '.(int) ($perf['chapter_count'] ?? 0);

        if (($perf['chapter_labels'] ?? []) !== []) {
            $labels = implode(', ', $perf['chapter_labels']);
            if ((int) ($perf['chapter_count'] ?? 0) > count($perf['chapter_labels'])) {
                $labels .= '…';
            }
            $lines[] = 'Tracking: '.$labels;
        }

        $done = (int) ($perf['done'] ?? 0);
        $total = (int) ($perf['total'] ?? 0);
        $correct = (int) ($perf['correct'] ?? $perf['scored_count'] ?? 0);
        $completion = $perf['completion_pct'] ?? null;
        $lines[] = 'Completion: '.$done.'/'.$total.' sums'
            .($completion !== null ? " ({$completion}%)" : '');

        if (($perf['score_pct'] ?? null) !== null) {
            $lines[] = 'Score: '.$correct.'/'.$done.' first-try ('.$perf['score_pct'].'%)';
        } else {
            $lines[] = 'Score: no scores yet';
        }

        $lines[] = 'Corrections: '.(int) ($perf['correction_done'] ?? 0).' done, '
            .(int) ($perf['correction_pending'] ?? 0).' pending';

        if ((int) ($perf['open_wrongs'] ?? 0) > 0) {
            $lines[] = 'Open wrong sums to revise: '.$perf['open_wrongs'];
        }

        if (($summary['pending_count'] ?? 0) > 0 || ($summary['overdue_count'] ?? 0) > 0) {
            $lines[] = '';
            $parts = [];
            if ((int) ($summary['overdue_count'] ?? 0) > 0) {
                $parts[] = $summary['overdue_count'].' overdue';
            }
            if ((int) ($summary['pending_count'] ?? 0) > 0) {
                $parts[] = $summary['pending_count'].' pending';
            }
            $lines[] = 'Also on dashboard: '.implode(', ', $parts).'.';
        }

        $lines[] = '';
        $lines[] = 'View details:';
        $lines[] = $summary['dashboard_url'] ?? '';
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildBalanceMessage(array $summary): string
    {
        $lines = [
            'Hello, this is Mentor Maths.',
            '',
            "Daily work reminder for {$summary['student_name']}",
            'As of: '.($summary['as_of_label'] ?? ''),
        ];

        if ($summary['class_name'] ?? null) {
            $lines[] = 'Class: '.$summary['class_name'];
        }

        if (($summary['stats']['balance_count'] ?? 0) === 0) {
            $lines[] = '';
            $lines[] = 'All caught up — nothing pending today.';
        } else {
            $this->appendOverdueSection($lines, $summary);
            $this->appendPendingSection($lines, $summary);
            $this->appendHelpSection($lines, $summary);
        }

        $lines[] = '';
        $lines[] = 'View details:';
        $lines[] = $summary['dashboard_url'];
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    /**
     * Ten single-line values, in the order of the mentor_maths_progress template.
     *
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    public function progressReportParameters(array $summary): array
    {
        $engagement = $summary['engagement'] ?? [];
        $stats = $summary['stats'] ?? [];
        $completedRows = $summary['completed'] ?? [];
        $loggedIn = (int) ($engagement['days_logged_in'] ?? 0);
        $worksheetCount = (int) ($stats['completed_count'] ?? count($completedRows));
        $score = $worksheetCount > 0
            ? (string) ($stats['overall_score_label'] ?? 'not scored')
            : 'no worksheets completed';

        $period = trim((string) ($summary['period_label'] ?? ''));
        if ($period === '') {
            $period = 'This period';
        }
        if (filled($summary['class_name'] ?? null)) {
            $period .= ', '.$summary['class_name'];
        }

        $chapterLines = [];
        foreach ($summary['chapter_performance'] ?? [] as $chapter) {
            $count = (int) ($chapter['sets_count'] ?? 0);
            $chapterScore = $chapter['label'] ?? 'not scored';
            $chapterLines[] = ($chapter['chapter_name'] ?? 'Chapter').': '.$count
                .' '.($count === 1 ? 'worksheet' : 'worksheets').', score '.$chapterScore;
        }
        if ($chapterLines === []) {
            $chapterLines = ['None this period'];
        }

        $weakLines = [];
        foreach ($completedRows as $row) {
            $percent = $row['latest_score_percent'] ?? null;
            if ($percent === null || (int) $percent >= 80) {
                continue;
            }

            $chapter = $row['chapter_name'] ?? 'Worksheet';
            $set = trim((string) ($row['set_code'] ?? ''));
            $label = $row['latest_score_label'] ?? ($percent.'%');
            $weakLines[] = trim($chapter.($set !== '' ? ' '.$set : '').' '.$label);
        }
        if ($weakLines === []) {
            $weakLines = [$worksheetCount > 0 ? 'None below 80 percent' : 'No worksheets to review'];
        }

        return [
            $this->templateLine((string) ($summary['student_name'] ?? 'the student')),
            $this->templateLine($period),
            $this->templateLine($loggedIn.' '.($loggedIn === 1 ? 'day' : 'days')),
            $this->templateLine((string) $worksheetCount),
            ...$this->fitLines($chapterLines, 3),
            $this->templateLine($score),
            ...$this->fitLines($weakLines, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildMonthReportMessage(array $summary): string
    {
        $params = $this->progressReportParameters($summary);

        return implode("\n", [
            'Hello, this is Mentor Maths.',
            '',
            'Progress report for '.$params[0],
            'Period: '.$params[1],
            '',
            'Logged in: '.$params[2],
            'Worksheets done: '.$params[3],
            '',
            'Chapter wise:',
            $params[4],
            $params[5],
            $params[6],
            '',
            'Performance score: '.$params[7],
            '',
            'Improvement area, marks below 80 percent:',
            $params[8],
            $params[9],
            '',
            'Thank you.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array{mobile: string, label: string, message: string, student_id: int, dashboard_url: ?string, progress_report_parameters: list<string>}>
     */
    public function notificationsForMonthReport(Student $student, array $summary): array
    {
        $parameters = $this->progressReportParameters($summary);
        $message = $this->buildMonthReportMessage($summary);
        $recipients = $this->contactService->recipientsForStudent($student);

        return array_map(fn (array $recipient) => [
            'mobile' => $recipient['mobile'],
            'label' => $recipient['label'],
            'message' => $message,
            'student_id' => $student->id,
            'dashboard_url' => $summary['dashboard_url'] ?? null,
            'progress_report_parameters' => $parameters,
        ], $recipients);
    }

    private function templateLine(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/ {2,}/', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : '—';
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function fitLines(array $lines, int $size): array
    {
        $lines = array_values(array_map(fn ($line) => $this->templateLine((string) $line), $lines));

        if (count($lines) > $size) {
            $head = array_slice($lines, 0, $size - 1);
            $extra = count($lines) - $size;
            $last = $lines[$size - 1].($extra > 0 ? ' and '.$extra.' more' : '');
            $head[] = $this->templateLine($last);

            return $head;
        }

        while (count($lines) < $size) {
            $lines[] = '—';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildMessage(array $summary): string
    {
        $lines = [
            'Hello, this is Mentor Maths.',
            '',
            "Progress summary for {$summary['student_name']}",
            'Period: '.($summary['period_label'] ?? $summary['as_of_label']),
        ];

        if ($summary['class_name'] ?? null) {
            $lines[] = 'Class: '.$summary['class_name'];
        }

        if (($summary['stats']['overall_score_label'] ?? null) && ($summary['stats']['completed_count'] ?? 0) > 0) {
            $lines[] = 'Overall score: '.$summary['stats']['overall_score_label'];
        }

        if ($summary['engagement'] ?? null) {
            $lines[] = 'Time spent: '.$summary['engagement']['time_spent_label'];
            $lines[] = 'Days logged in: '.$summary['engagement']['days_logged_in']
                .' of '.$summary['engagement']['total_days']
                .' (not logged in: '.$summary['engagement']['days_not_logged_in'].')';
        }

        if ($summary['mentor_remark'] ?? null) {
            $lines[] = '';
            $lines[] = 'Mentor remark:';
            $lines[] = $summary['mentor_remark'];
        }

        $this->appendCompletedSection($lines, $summary);
        $this->appendOverdueSection($lines, $summary);
        $this->appendPendingSection($lines, $summary);
        $this->appendHelpSection($lines, $summary);
        $this->appendRecentSection($lines, $summary);

        $lines[] = '';
        $lines[] = 'View details:';
        $lines[] = $summary['dashboard_url'];
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $summary
     */
    private function appendCompletedSection(array &$lines, array $summary): void
    {
        $lines[] = '';
        $label = ($summary['period_filtered'] ?? false)
            ? 'Completed in period ('.($summary['stats']['completed_count'] ?? 0).'):'
            : 'Completed ('.($summary['stats']['completed_count'] ?? 0).'):';
        $lines[] = $label;

        if ($summary['completed'] === []) {
            $lines[] = ($summary['period_filtered'] ?? false)
                ? '— none completed in this period'
                : '— none yet';

            return;
        }

        $groups = $summary['completed_by_date'] ?? [];

        if ($groups === []) {
            $groups = collect($summary['completed_by_chapter'] ?? [])
                ->map(fn (array $group) => [
                    'date_label' => $group['chapter_name'],
                    'rows' => $group['rows'],
                ])
                ->all();
        }

        foreach ($groups as $group) {
            $lines[] = '';
            $lines[] = $group['date_label'];
            $lines[] = 'Set · Type · Topic · Score · Review';

            foreach ($group['rows'] as $row) {
                $score = ProgressSummaryTable::scoreLabel($row).ProgressSummaryTable::attemptSuffix($row);
                $lines[] = implode(' · ', [
                    $row['set_code'],
                    $row['kind_label'],
                    ProgressSummaryTable::detailLabel($row),
                    $score,
                    ProgressSummaryTable::reviewLabel($row),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $summary
     */
    private function appendOverdueSection(array &$lines, array $summary): void
    {
        if (($summary['stats']['overdue_count'] ?? 0) === 0) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Overdue ('.$summary['stats']['overdue_count'].'):';

        foreach ($summary['overdue_by_chapter'] as $group) {
            $lines[] = '';
            $lines[] = $group['chapter_name'];
            $lines[] = 'Set · Type · Topic · Due';

            foreach ($group['rows'] as $row) {
                $lines[] = implode(' · ', [
                    $row['set_code'],
                    $row['kind_label'],
                    ProgressSummaryTable::detailLabel($row),
                    ProgressSummaryTable::targetDateLabel($row),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $summary
     */
    private function appendPendingSection(array &$lines, array $summary): void
    {
        if (($summary['stats']['pending_count'] ?? 0) === 0) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Pending ('.$summary['stats']['pending_count'].'):';

        foreach ($summary['pending_by_chapter'] as $group) {
            $lines[] = '';
            $lines[] = $group['chapter_name'];
            $lines[] = 'Set · Type · Topic · Target';

            foreach ($group['rows'] as $row) {
                $lines[] = implode(' · ', [
                    $row['set_code'],
                    $row['kind_label'],
                    ProgressSummaryTable::detailLabel($row),
                    ProgressSummaryTable::targetDateLabel($row),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $summary
     */
    private function appendHelpSection(array &$lines, array $summary): void
    {
        if (($summary['stats']['help_count'] ?? 0) === 0) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Need teacher help ('.$summary['stats']['help_count'].'):';

        foreach ($summary['help_requests'] as $item) {
            $setCode = $item['set_code'] ?? 'Practice';
            $lines[] = "• {$setCode} — needs explanation in class";
        }
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $summary
     */
    private function appendRecentSection(array &$lines, array $summary): void
    {
        if (($summary['stats']['recent_count'] ?? 0) === 0 || ! ($summary['period_label'] ?? null)) {
            return;
        }

        $lines[] = '';
        $lines[] = 'Completed this period ('.$summary['stats']['recent_count'].'):';

        foreach ($summary['recently_completed_by_chapter'] as $group) {
            $lines[] = '';
            $lines[] = $group['chapter_name'];
            $lines[] = 'Date · Set · Type · Topic · Score';

            foreach ($group['rows'] as $row) {
                $lines[] = implode(' · ', [
                    ProgressSummaryTable::submittedDateLabel($row) ?? '—',
                    $row['set_code'],
                    $row['kind_label'],
                    ProgressSummaryTable::detailLabel($row),
                    ProgressSummaryTable::scoreLabel($row),
                ]);
            }
        }
    }
}
