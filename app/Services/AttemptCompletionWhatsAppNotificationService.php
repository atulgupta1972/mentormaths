<?php

namespace App\Services;

use App\Models\Student;
use App\Support\WhatsApp\WhatsAppTemplateBuilder;

class AttemptCompletionWhatsAppNotificationService
{
    public function __construct(
        private StudentMentorService $mentorService,
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array{mobile: string, label: string, message: string, student_id: int, dashboard_url: string}>
     */
    public function notificationsForCompletion(Student $student, array $summary): array
    {
        $mentor = $this->mentorService->resolve($student);

        if (! filled($mentor['mobile'] ?? null)) {
            return [];
        }

        $dashboard = route('dashboard');
        $message = $this->buildMessage($summary, $dashboard);

        return [[
            'mobile' => $mentor['mobile'],
            'label' => filled($mentor['name'] ?? null)
                ? "Mentor ({$mentor['name']})"
                : ($mentor['label'] ?? 'Mentor'),
            'message' => $message,
            'student_id' => $student->id,
            'dashboard_url' => $dashboard,
        ]];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildMessage(array $summary, ?string $dashboardUrl = null): string
    {
        $dashboard = $dashboardUrl ?: route('dashboard');
        $studentName = (string) ($summary['student_name'] ?? 'Student');
        $kind = (string) ($summary['kind_label'] ?? 'Practice');
        $setCode = (string) ($summary['set_code'] ?? '');
        $tier = (string) ($summary['tier_label'] ?? '');
        $scoreLabel = (string) ($summary['score_label'] ?? '—');

        $lines = [
            'Hello, this is Mentor Maths.',
            '',
            "{$studentName} finished {$kind}".($setCode !== '' ? " {$setCode}" : '').'.',
        ];

        if ($tier !== '') {
            $lines[] = $tier;
        }

        if (filled($summary['scope_line'] ?? null)) {
            $lines[] = (string) $summary['scope_line'];
        } elseif (filled($summary['chapter_name'] ?? null)) {
            $lines[] = 'Chapter: '.$summary['chapter_name'];
        }

        $lines[] = "Score: {$scoreLabel}";

        if (! empty($summary['is_guided'])) {
            $firstTry = (int) ($summary['first_try_correct'] ?? 0);
            $afterHelp = (int) ($summary['corrected_after_help'] ?? 0);
            $gaveUp = (int) ($summary['given_up'] ?? 0);
            $lines[] = "First try correct: {$firstTry} · After help: {$afterHelp} · Gave up: {$gaveUp}";
        }

        $wrongLines = $this->wrongSumLines($summary['wrong_questions'] ?? []);
        $lines[] = '';
        if ($wrongLines === []) {
            $lines[] = 'Wrong sums: none';
        } else {
            $lines[] = 'Wrong sums:';
            array_push($lines, ...$wrongLines);
        }

        $lines[] = '';
        $lines[] = 'View details:';
        $lines[] = $dashboard;
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<array<string, mixed>>  $wrongQuestions
     * @return list<string>
     */
    private function wrongSumLines(array $wrongQuestions): array
    {
        if ($wrongQuestions === []) {
            return [];
        }

        $max = 12;
        $lines = [];

        foreach (array_slice(array_values($wrongQuestions), 0, $max) as $row) {
            $number = (int) ($row['number'] ?? 0);
            $label = trim((string) ($row['outcome_label'] ?? 'Wrong'));
            $topic = trim((string) ($row['topic_name'] ?? ''));

            $line = 'Q'.$number;
            if ($topic !== '') {
                $line .= ' · '.$topic;
            }
            if ($label !== '') {
                $line .= ' — '.$label;
            }

            $lines[] = WhatsAppTemplateBuilder::singleLine($line);
        }

        $extra = count($wrongQuestions) - $max;
        if ($extra > 0) {
            $lines[] = '… and '.$extra.' more';
        }

        return $lines;
    }
}
