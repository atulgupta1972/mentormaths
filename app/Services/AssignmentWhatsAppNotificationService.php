<?php

namespace App\Services;

use App\Models\Student;
use App\Models\Worksheet;
use App\Support\PracticeSetTier;
use Carbon\Carbon;

class AssignmentWhatsAppNotificationService
{
    public function __construct(
        private StudentNotificationContactService $contactService,
    ) {}

    /**
     * @return list<array{mobile: string, label: string, message: string}>
     */
    public function notificationsForAssignment(
        Student $student,
        Worksheet $worksheet,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $worksheet->loadMissing([
            'topic.chapter',
            'chapter',
        ]);

        if (! isset($worksheet->questions_count)) {
            $worksheet->loadCount('questions');
        }

        $message = $this->buildMessage($student, $worksheet, $dueDate, $notes);
        $recipients = $this->contactService->recipientsForStudent($student);

        return array_map(fn (array $recipient) => [
            'mobile' => $recipient['mobile'],
            'label' => $recipient['label'],
            'message' => $message,
        ], $recipients);
    }

    /**
     * @param  list<Student>  $students
     * @return list<array{mobile: string, label: string, message: string}>
     */
    public function notificationsForBulkAssignment(
        array $students,
        Worksheet $worksheet,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $notifications = [];

        foreach ($students as $student) {
            array_push($notifications, ...$this->notificationsForAssignment($student, $worksheet, $dueDate, $notes));
        }

        return $notifications;
    }

    public function buildMessage(
        Student $student,
        Worksheet $worksheet,
        string $dueDate,
        ?string $notes = null,
    ): string {
        $worksheet->loadMissing([
            'topic.chapter',
            'chapter',
        ]);

        if (! isset($worksheet->questions_count)) {
            $worksheet->loadCount('questions');
        }

        $questionCount = (int) ($worksheet->questions_count ?? 0);
        $kindLabel = $worksheet->isChapterScope() ? 'Test' : 'Practice';
        $scopeLine = $this->scopeLine($worksheet);
        $timeEstimate = $this->estimateTimeLabel($worksheet, $questionCount);
        $dueLabel = Carbon::parse($dueDate)->format('d M Y');
        $login = route('login');
        $dashboard = route('dashboard');

        $lines = [
            'Hello, this is Mentor Maths.',
            '',
            "New {$kindLabel} assigned for {$student->name}:",
            '',
            "Set: {$worksheet->set_code} — {$worksheet->tier_label} {$worksheet->scopeLabel()}",
        ];

        if ($scopeLine) {
            $lines[] = $scopeLine;
        }

        $lines[] = "{$questionCount} question".($questionCount === 1 ? '' : 's')." · {$timeEstimate}";
        $lines[] = "Complete by: {$dueLabel}";

        if (filled($notes)) {
            $lines[] = '';
            $lines[] = "Note: {$notes}";
        }

        $lines[] = '';
        $lines[] = 'Login and start from your dashboard:';
        $lines[] = $dashboard;
        $lines[] = "Login: {$login}";
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    private function scopeLine(Worksheet $worksheet): ?string
    {
        if ($worksheet->isChapterScope()) {
            $chapter = $worksheet->chapter?->name;

            return $chapter ? "Chapter: {$chapter}" : null;
        }

        $topic = $worksheet->topic?->name;
        $chapter = $worksheet->topic?->chapter?->name;

        if ($topic && $chapter) {
            return "Topic: {$topic} ({$chapter})";
        }

        if ($topic) {
            return "Topic: {$topic}";
        }

        return null;
    }

    public function estimateTimeLabel(Worksheet $worksheet, int $questionCount): string
    {
        if ($questionCount <= 0) {
            return 'approx 10–15 min';
        }

        $minutesPerQuestion = match ($worksheet->tier) {
            PracticeSetTier::CHAMPION => 2.5,
            PracticeSetTier::CHAPTER_TEST => 3.0,
            PracticeSetTier::BUILDER => 2.0,
            default => 1.5,
        };

        $base = (int) max(5, round($questionCount * $minutesPerQuestion));
        $upper = $base + max(5, (int) round($base * 0.25));

        return "approx {$base}–{$upper} min";
    }
}
