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
        $lines[] = 'Start here:';
        $lines[] = $dashboard;
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

    /**
     * @param  list<Worksheet>  $worksheets
     * @return list<array{mobile: string, label: string, message: string}>
     */
    public function notificationsForMultiAssignment(
        Student $student,
        array $worksheets,
        string $dueDate,
        ?string $notes = null,
    ): array {
        if ($worksheets === []) {
            return [];
        }

        if (count($worksheets) === 1) {
            return $this->notificationsForAssignment($student, $worksheets[0], $dueDate, $notes);
        }

        $message = $this->buildMultiAssignmentMessage($student, $worksheets, $dueDate, $notes);
        $recipients = $this->contactService->recipientsForStudent($student);

        return array_map(fn (array $recipient) => [
            'mobile' => $recipient['mobile'],
            'label' => $recipient['label'],
            'message' => $message,
        ], $recipients);
    }

    /**
     * @param  array<int, list<Worksheet>>  $worksheetsByStudentId
     * @return list<array{mobile: string, label: string, message: string}>
     */
    public function notificationsForClassMultiAssignment(
        array $worksheetsByStudentId,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $notifications = [];

        foreach ($worksheetsByStudentId as $studentId => $worksheets) {
            if ($worksheets === []) {
                continue;
            }

            $student = Student::query()->find($studentId);

            if (! $student) {
                continue;
            }

            array_push(
                $notifications,
                ...$this->notificationsForMultiAssignment($student, $worksheets, $dueDate, $notes),
            );
        }

        return $notifications;
    }

    /**
     * @param  list<Worksheet>  $worksheets
     */
    public function buildMultiAssignmentMessage(
        Student $student,
        array $worksheets,
        string $dueDate,
        ?string $notes = null,
    ): string {
        $dueLabel = Carbon::parse($dueDate)->format('d M Y');
        $dashboard = route('dashboard');

        $lines = [
            'Hello, this is Mentor Maths.',
            '',
            "New work assigned for {$student->name} (complete by {$dueLabel}):",
            '',
        ];

        $totalMin = 0;
        $totalMax = 0;

        foreach (array_values($worksheets) as $index => $worksheet) {
            $worksheet->loadMissing(['topic.chapter', 'chapter']);

            if (! isset($worksheet->questions_count)) {
                $worksheet->loadCount('questions');
            }

            $questionCount = (int) ($worksheet->questions_count ?? 0);
            $kindLabel = $worksheet->isChapterScope() ? 'Test' : 'Practice';
            $scope = $this->scopeLine($worksheet);
            $timeEstimate = $this->estimateTimeLabel($worksheet, $questionCount);
            [$minMinutes, $maxMinutes] = $this->estimateTimeRange($worksheet, $questionCount);
            $totalMin += $minMinutes;
            $totalMax += $maxMinutes;

            $detail = "{$worksheet->set_code} — {$worksheet->tier_label} {$kindLabel}";
            if ($scope) {
                $detail .= ' · '.str_replace(['Topic: ', 'Chapter: '], '', $scope);
            }
            $detail .= " · {$questionCount} Q · {$timeEstimate}";

            $lines[] = ($index + 1).". {$detail}";
        }

        if (count($worksheets) > 1) {
            $lines[] = '';
            $lines[] = "Total approx {$totalMin}–{$totalMax} min";
        }

        if (filled($notes)) {
            $lines[] = '';
            $lines[] = "Note: {$notes}";
        }

        $lines[] = '';
        $lines[] = 'Start here:';
        $lines[] = $dashboard;
        $lines[] = '';
        $lines[] = 'Thank you.';

        return implode("\n", $lines);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function estimateTimeRange(Worksheet $worksheet, int $questionCount): array
    {
        $label = $this->estimateTimeLabel($worksheet, $questionCount);

        if (preg_match('/approx (\d+)–(\d+) min/', $label, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [10, 15];
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
