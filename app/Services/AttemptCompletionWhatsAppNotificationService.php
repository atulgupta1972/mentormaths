<?php

namespace App\Services;

use App\Models\Student;
use App\Support\StudentIdentity;
use App\Support\WhatsApp\WhatsAppTemplateBuilder;

class AttemptCompletionWhatsAppNotificationService
{
    public function __construct(
        private StudentMentorService $mentorService,
        private StudentNotificationContactService $contactService,
    ) {}

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array{mobile: string, label: string, message: string, student_id: int, dashboard_url: string, completion_parameters: list<string>}>
     */
    public function notificationsForCompletion(Student $student, array $summary): array
    {
        $dashboard = route('dashboard');
        $parameters = $this->completionParameters($summary, $dashboard);
        $message = $this->buildMessage($summary, $dashboard);
        $recipients = $this->recipients($student);

        return array_map(fn (array $recipient) => [
            'mobile' => $recipient['mobile'],
            'label' => $recipient['label'],
            'message' => $message,
            'student_id' => $student->id,
            'dashboard_url' => $dashboard,
            'completion_parameters' => $parameters,
        ], $recipients);
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<string>
     */
    public function completionParameters(array $summary, ?string $dashboardUrl = null): array
    {
        $dashboard = $dashboardUrl ?: route('dashboard');
        $studentName = (string) ($summary['student_name'] ?? 'Student');
        $kind = (string) ($summary['kind_label'] ?? 'Practice');
        $setCode = (string) ($summary['set_code'] ?? '');
        $tier = trim((string) ($summary['tier_label'] ?? ''));
        $scope = trim((string) ($summary['scope_line'] ?? ''));
        if ($scope === '' && filled($summary['chapter_name'] ?? null)) {
            $scope = 'Chapter: '.$summary['chapter_name'];
        }

        $headline = "{$studentName} finished {$kind}".($setCode !== '' ? " {$setCode}" : '').'.';
        $scoreLabel = (string) ($summary['score_label'] ?? '—');

        $guidedLine = '—';
        if (! empty($summary['is_guided'])) {
            $firstTry = (int) ($summary['first_try_correct'] ?? 0);
            $afterHelp = (int) ($summary['corrected_after_help'] ?? 0);
            $gaveUp = (int) ($summary['given_up'] ?? 0);
            $guidedLine = "First try correct: {$firstTry} · After help: {$afterHelp} · Gave up: {$gaveUp}";
        }

        $wrongLines = $this->wrongSumLines($summary['wrong_questions'] ?? []);
        if ($wrongLines === []) {
            $wrongLines = ['none'];
        }
        $wrongFitted = $this->fitLines($wrongLines, 3);

        return [
            WhatsAppTemplateBuilder::singleLine($headline),
            WhatsAppTemplateBuilder::singleLine($tier !== '' ? $tier : '—'),
            WhatsAppTemplateBuilder::singleLine($scope !== '' ? $scope : '—'),
            WhatsAppTemplateBuilder::singleLine($scoreLabel),
            WhatsAppTemplateBuilder::singleLine($guidedLine),
            $wrongFitted[0],
            $wrongFitted[1],
            $wrongFitted[2],
            WhatsAppTemplateBuilder::singleLine($dashboard),
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function buildMessage(array $summary, ?string $dashboardUrl = null): string
    {
        $params = $this->completionParameters($summary, $dashboardUrl);

        $lines = [
            'Hello, this is Mentor Maths.',
            '',
            $params[0],
        ];

        if ($params[1] !== '—') {
            $lines[] = $params[1];
        }
        if ($params[2] !== '—') {
            $lines[] = $params[2];
        }

        $lines[] = 'Score: '.$params[3];

        if ($params[4] !== '—') {
            $lines[] = $params[4];
        }

        $lines[] = '';
        $lines[] = 'Wrong sums:';
        if ($params[5] === 'none' && $params[6] === '—' && $params[7] === '—') {
            $lines[] = 'none';
        } else {
            foreach ([$params[5], $params[6], $params[7]] as $wrong) {
                if ($wrong !== '—') {
                    $lines[] = $wrong;
                }
            }
        }

        $lines[] = '';
        $lines[] = 'View details:';
        $lines[] = $params[8];
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    /**
     * @return list<array{key: string, label: string, mobile: string}>
     */
    private function recipients(Student $student): array
    {
        $recipients = [];
        $seen = [];

        $add = function (string $key, string $label, ?string $mobile) use (&$recipients, &$seen): void {
            if (! filled($mobile)) {
                return;
            }

            $normalized = StudentIdentity::normalizeMobile($mobile);
            if ($normalized === null || isset($seen[$normalized])) {
                return;
            }

            $seen[$normalized] = true;
            $recipients[] = [
                'key' => $key,
                'label' => $label,
                'mobile' => $mobile,
            ];
        };

        $mentor = $this->mentorService->resolve($student);
        $add(
            'mentor',
            filled($mentor['name'] ?? null)
                ? "Mentor ({$mentor['name']})"
                : ($mentor['label'] ?? 'Mentor'),
            $mentor['mobile'] ?? null,
        );

        foreach ($this->contactService->recipientsForStudent($student) as $contact) {
            // Parents (and student if notify) — same Notify ticks as other WhatsApp alerts.
            if (($contact['key'] ?? '') === 'student') {
                continue;
            }

            $add($contact['key'], $contact['label'], $contact['mobile']);
        }

        return $recipients;
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

        $lines = [];

        foreach (array_values($wrongQuestions) as $row) {
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

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function fitLines(array $lines, int $size): array
    {
        $lines = array_values(array_map(
            fn ($line) => WhatsAppTemplateBuilder::singleLine((string) $line),
            $lines,
        ));

        if (count($lines) > $size) {
            $head = array_slice($lines, 0, $size - 1);
            $extra = count($lines) - ($size - 1);
            $head[] = WhatsAppTemplateBuilder::singleLine('… and '.$extra.' more');

            return $head;
        }

        while (count($lines) < $size) {
            $lines[] = '—';
        }

        return $lines;
    }
}
