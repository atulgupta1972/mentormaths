<?php

namespace App\Services;

use App\Models\TextbookChapter;
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
     * @param  list<array{set_code: string, q_from: int, q_to: int, description: string}>  $plan
     */
    public function summary(array $plan): string
    {
        if ($plan === []) {
            return '';
        }

        if (count($plan) === 1) {
            $row = $plan[0];
            $label = trim($row['description']) !== '' ? " ({$row['description']})" : '';

            return "{$row['set_code']}{$label} · Q{$row['q_from']}–{$row['q_to']}";
        }

        $counts = implode('+', array_map(fn (array $row) => $row['q_to'] - $row['q_from'] + 1, $plan));
        $codes = implode(', ', array_column($plan, 'set_code'));

        return count($plan)." sets ({$counts}): {$codes}";
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
