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
        ], $recipients);
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
