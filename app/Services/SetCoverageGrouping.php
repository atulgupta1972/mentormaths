<?php

namespace App\Services;

use App\Support\PracticeSetMasterProfile;
use App\Support\PracticeSetTier;
use Closure;

class SetCoverageGrouping
{
    /**
     * Group chapter set items under Learner / Achiever / Expert, with type rows (mcq, fill blank, written, book).
     *
     * @param  array<string, mixed>  $items
     * @param  Closure(array<string, mixed>): array<string, mixed>|null  $mapItem
     * @return list<array{key: string, label: string, tier: string|null, color: string|null, kind: string|null, items: list<array<string, mixed>>}>
     */
    public function formatDetailGroups(array $items, ?Closure $mapItem = null): array
    {
        $mapItem ??= fn (array $item) => $item;
        $groups = [];

        $typeBuckets = [
            'practice' => 'MCQ',
            'fill_blank' => 'Fill in blank',
            'written' => 'Written',
            'test' => 'Test',
            'formula' => 'Formula',
            'practice_correction' => 'Practice · Correction',
        ];

        foreach (PracticeSetMasterProfile::keys() as $tier) {
            $profile = PracticeSetMasterProfile::profile($tier);
            $label = PracticeSetTier::label($tier);
            $color = $profile['color'] ?? 'slate';

            foreach ($typeBuckets as $key => $typeLabel) {
                $rows = collect($items[$key] ?? [])
                    ->filter(fn (array $item) => $this->itemTier($item) === $tier)
                    ->map(fn (array $item) => $mapItem($item))
                    ->values()
                    ->all();

                if ($rows === []) {
                    continue;
                }

                $groups[] = [
                    'key' => "{$tier}:{$key}",
                    'label' => "{$label} · {$typeLabel}",
                    'tier' => $tier,
                    'color' => $color,
                    'kind' => $key,
                    'items' => $rows,
                ];
            }

            $bookRows = collect($items['books'] ?? [])
                ->flatten(1)
                ->filter(fn (array $item) => $this->itemTier($item) === $tier)
                ->map(fn (array $item) => $mapItem($item))
                ->values()
                ->all();

            if ($bookRows !== []) {
                $groups[] = [
                    'key' => "{$tier}:books",
                    'label' => "{$label} · Book",
                    'tier' => $tier,
                    'color' => $color,
                    'kind' => 'books',
                    'items' => $bookRows,
                ];
            }
        }

        // Chapter tests / uncategorised leftovers (no master profile tier).
        foreach ($typeBuckets as $key => $typeLabel) {
            $rows = collect($items[$key] ?? [])
                ->filter(fn (array $item) => ! PracticeSetMasterProfile::isValid($this->itemTier($item)))
                ->map(fn (array $item) => $mapItem($item))
                ->values()
                ->all();

            if ($rows === []) {
                continue;
            }

            $groups[] = [
                'key' => "other:{$key}",
                'label' => $typeLabel,
                'tier' => null,
                'color' => null,
                'kind' => $key,
                'items' => $rows,
            ];
        }

        $otherBooks = collect($items['books'] ?? [])
            ->flatten(1)
            ->filter(fn (array $item) => ! PracticeSetMasterProfile::isValid($this->itemTier($item)))
            ->map(fn (array $item) => $mapItem($item))
            ->values()
            ->all();

        if ($otherBooks !== []) {
            $groups[] = [
                'key' => 'other:books',
                'label' => 'Books',
                'tier' => null,
                'color' => null,
                'kind' => 'books',
                'items' => $otherBooks,
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function itemTier(array $item): string
    {
        return (string) ($item['tier'] ?? PracticeSetTier::STARTER);
    }
}
