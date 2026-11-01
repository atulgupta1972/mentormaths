<?php

namespace App\Services;

use App\Models\TextbookChapter;
use App\Models\Worksheet;
use InvalidArgumentException;

class TextbookMcqSetPlanService
{
    public function __construct(
        private TextbookSetCodeService $setCodeService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{set_code: string, q_from: int, q_to: int, description: string}>
     */
    public function parseFromPayload(array $payload, TextbookChapter $chapter, int $questionCount): array
    {
        $raw = $payload['set_plan']
            ?? $payload['mcq_set_plan']
            ?? $payload['set_matrix']
            ?? null;

        if (! is_array($raw) || $raw === []) {
            return $this->defaultPlan($chapter, $questionCount);
        }

        $plan = [];

        foreach (array_values($raw) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $plan[] = $this->normalizeRow($row, $chapter, $index + 1);
        }

        if ($plan === []) {
            return $this->defaultPlan($chapter, $questionCount);
        }

        $this->validate($plan, $questionCount);

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{set_code: string, q_from: int, q_to: int, description: string}>
     */
    public function normalizePlanRows(array $rows, TextbookChapter $chapter, int $questionCount): array
    {
        $plan = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $plan[] = $this->normalizeRow($row, $chapter, $index + 1);
        }

        if ($plan === []) {
            return $this->defaultPlan($chapter, $questionCount);
        }

        $this->validate($plan, $questionCount);

        return $plan;
    }

    /**
     * @return list<array{set_code: string, q_from: int, q_to: int, description: string}>
     */
    public function defaultPlan(TextbookChapter $chapter, int $questionCount): array
    {
        if ($questionCount <= 0) {
            return [];
        }

        return [[
            'set_code' => $this->setCodeService->codes($chapter)['mcq'],
            'q_from' => 1,
            'q_to' => $questionCount,
            'description' => '',
        ]];
    }

    /**
     * When a published chapter was split into more worksheets than the saved plan,
     * rewrite the plan from those worksheets so the publish page shows each part.
     *
     * @return list<array{set_code: string, q_from: int, q_to: int, description: string}>
     */
    public function alignStoredPlanWithWorksheets(TextbookChapter $chapter): array
    {
        $stored = $this->storedPlanRows($chapter);
        $fromWorksheets = $this->planFromMcqWorksheets($chapter, $stored);

        if ($fromWorksheets === [] || count($fromWorksheets) <= count($stored)) {
            return $stored;
        }

        $chapter->update(['mcq_set_plan' => $fromWorksheets]);

        return $fromWorksheets;
    }

    /**
     * @param  list<array{set_code: string, q_from: int, q_to: int, description: string}>  $stored
     * @return list<array{set_code: string, q_from: int, q_to: int, description: string}>
     */
    public function planFromMcqWorksheets(TextbookChapter $chapter, array $stored = []): array
    {
        $ids = $chapter->mcqWorksheetIds();
        if ($ids === []) {
            return [];
        }

        $sheets = Worksheet::query()
            ->whereIn('id', $ids)
            ->withCount('questions')
            ->get()
            ->keyBy('id');

        $descriptions = [];
        foreach ($stored as $row) {
            $code = trim((string) ($row['set_code'] ?? ''));
            if ($code !== '') {
                $descriptions[$code] = trim((string) ($row['description'] ?? ''));
            }
        }

        $plan = [];
        $from = 1;

        foreach ($ids as $id) {
            $sheet = $sheets->get($id);
            if (! $sheet) {
                continue;
            }

            $count = (int) $sheet->questions_count;
            if ($count < 1) {
                continue;
            }

            $code = trim((string) $sheet->set_code);
            $to = $from + $count - 1;
            $plan[] = [
                'set_code' => $code,
                'q_from' => $from,
                'q_to' => $to,
                'description' => $descriptions[$code] ?? '',
            ];
            $from = $to + 1;
        }

        return $plan;
    }

    /**
     * @return list<array{set_code: string, q_from: int, q_to: int, description: string}>
     */
    private function storedPlanRows(TextbookChapter $chapter): array
    {
        $stored = $chapter->mcq_set_plan;
        if (! is_array($stored)) {
            return [];
        }

        $rows = [];
        foreach ($stored as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'set_code' => trim((string) ($row['set_code'] ?? '')),
                'q_from' => (int) ($row['q_from'] ?? 0),
                'q_to' => (int) ($row['q_to'] ?? 0),
                'description' => trim((string) ($row['description'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{set_code: string, q_from: int, q_to: int, description: string}>  $plan
     */
    public function summary(array $plan): string
    {
        $rows = [];

        foreach ($plan as $row) {
            if (! is_array($row)) {
                continue;
            }

            $setCode = trim((string) ($row['set_code'] ?? ''));
            $qFrom = (int) ($row['q_from'] ?? 0);
            $qTo = (int) ($row['q_to'] ?? 0);

            if ($setCode === '' || $qFrom < 1 || $qTo < $qFrom) {
                continue;
            }

            $rows[] = [
                'set_code' => $setCode,
                'q_from' => $qFrom,
                'q_to' => $qTo,
                'description' => trim((string) ($row['description'] ?? '')),
            ];
        }

        if ($rows === []) {
            return '';
        }

        if (count($rows) === 1) {
            $row = $rows[0];
            $label = $row['description'] !== '' ? " ({$row['description']})" : '';

            return "{$row['set_code']}{$label} · Q{$row['q_from']}–{$row['q_to']}";
        }

        $counts = implode('+', array_map(fn (array $row) => $row['q_to'] - $row['q_from'] + 1, $rows));
        $codes = implode(', ', array_column($rows, 'set_code'));

        return count($rows)." sets ({$counts}): {$codes}";
    }

    /**
     * @param  list<array{set_code: string, q_from: int, q_to: int, description: string}>  $plan
     */
    public function validate(array $plan, int $questionCount): void
    {
        if ($questionCount <= 0) {
            throw new InvalidArgumentException('Add at least one MCQ before defining a set plan.');
        }

        if ($plan === []) {
            throw new InvalidArgumentException('Define at least one MCQ set in the set plan.');
        }

        $covered = [];

        foreach ($plan as $index => $row) {
            $label = 'Set plan row '.($index + 1);
            $setCode = trim((string) ($row['set_code'] ?? ''));
            $qFrom = (int) ($row['q_from'] ?? 0);
            $qTo = (int) ($row['q_to'] ?? 0);

            if ($setCode === '') {
                throw new InvalidArgumentException("{$label}: set code is required.");
            }

            if ($qFrom < 1 || $qTo < $qFrom) {
                throw new InvalidArgumentException("{$label} ({$setCode}): q_from and q_to must be valid (1-based, q_to ≥ q_from).");
            }

            if ($qTo > $questionCount) {
                throw new InvalidArgumentException("{$label} ({$setCode}): q_to ({$qTo}) exceeds imported question count ({$questionCount}).");
            }

            for ($q = $qFrom; $q <= $qTo; $q++) {
                if (isset($covered[$q])) {
                    throw new InvalidArgumentException("Question {$q} appears in more than one set ({$covered[$q]} and {$setCode}).");
                }

                $covered[$q] = $setCode;
            }
        }

        for ($q = 1; $q <= $questionCount; $q++) {
            if (! isset($covered[$q])) {
                throw new InvalidArgumentException("Question {$q} is not assigned to any set in the plan.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{set_code: string, q_from: int, q_to: int, description: string}
     */
    private function normalizeRow(array $row, TextbookChapter $chapter, int $fallbackPart): array
    {
        $setCode = trim((string) ($row['set_code'] ?? $row['set_no'] ?? $row['set_number'] ?? ''));

        if ($setCode === '') {
            $setCode = $this->setCodeService->mcqPartCode($chapter, $fallbackPart, max(2, $fallbackPart));
        }

        return [
            'set_code' => $setCode,
            'q_from' => (int) ($row['q_from'] ?? $row['from'] ?? 0),
            'q_to' => (int) ($row['q_to'] ?? $row['to'] ?? 0),
            'description' => trim((string) ($row['description'] ?? $row['desc'] ?? $row['label'] ?? '')),
        ];
    }
}
