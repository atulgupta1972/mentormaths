<?php

namespace App\Services;

use App\Models\Student;
use App\Support\DateLabels;
use App\Support\ScoreLabel;

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
            'As on: '.$summary['as_of_label'],
        ];

        if ($summary['class_name'] ?? null) {
            $lines[] = 'Class: '.$summary['class_name'];
        }

        if (($summary['stats']['overall_score_label'] ?? null) && ($summary['stats']['completed_count'] ?? 0) > 0) {
            $lines[] = 'Overall score: '.$summary['stats']['overall_score_label'];
        }

        if ($summary['period_label'] ?? null) {
            $lines[] = 'Period: '.$summary['period_label'];
        }

        $lines[] = '';
        $lines[] = 'Completed ('.($summary['stats']['completed_count'] ?? 0).'):';

        if ($summary['completed'] === []) {
            $lines[] = '— none yet';
        } else {
            foreach ($summary['completed'] as $row) {
                $scoreLabel = $row['latest_score_label']
                    ?? ScoreLabel::format($row['latest_score'] ?? null, $row['latest_max_score'] ?? null)
                    ?? '—';
                $line = "• {$row['set_code']} — {$scoreLabel}";

                if (($row['latest_attempt_number'] ?? 0) > 1) {
                    $line .= ' · Attempt '.$row['latest_attempt_number'];
                }

                $reviewCount = count($row['review_items'] ?? []);

                if ($reviewCount > 0) {
                    $line .= " · {$reviewCount} need review";
                }

                $lines[] = $line;
            }
        }

        if (($summary['stats']['overdue_count'] ?? 0) > 0) {
            $lines[] = '';
            $lines[] = 'Overdue ('.$summary['stats']['overdue_count'].'):';

            foreach ($summary['overdue'] as $row) {
                $due = $row['target_date']
                    ? DateLabels::formatDate($row['target_date'])
                    : 'no date';

                $lines[] = "• {$row['set_code']} — due {$due}";
            }
        }

        if (($summary['stats']['pending_count'] ?? 0) > 0) {
            $lines[] = '';
            $lines[] = 'Pending ('.$summary['stats']['pending_count'].'):';

            foreach ($summary['pending'] as $row) {
                $due = $row['target_date']
                    ? DateLabels::formatDate($row['target_date'])
                    : 'no date';

                $lines[] = "• {$row['set_code']} — due {$due}";
            }
        }

        if (($summary['stats']['help_count'] ?? 0) > 0) {
            $lines[] = '';
            $lines[] = 'Need teacher help ('.$summary['stats']['help_count'].'):';

            foreach ($summary['help_requests'] as $item) {
                $setCode = $item['set_code'] ?? 'Practice';
                $lines[] = "• {$setCode} — needs explanation in class";
            }
        }

        if (($summary['stats']['recent_count'] ?? 0) > 0 && ($summary['period_label'] ?? null)) {
            $lines[] = '';
            $lines[] = 'Completed this period ('.$summary['stats']['recent_count'].'):';

            foreach ($summary['recently_completed'] as $row) {
                $scoreLabel = $row['latest_score_label']
                    ?? ScoreLabel::format($row['latest_score'] ?? null, $row['latest_max_score'] ?? null)
                    ?? '—';
                $lines[] = "• {$row['set_code']} — {$scoreLabel}";
            }
        }

        $lines[] = '';
        $lines[] = 'View details:';
        $lines[] = $summary['dashboard_url'];
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }
}
