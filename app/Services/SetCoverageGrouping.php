<?php

namespace App\Services;

use App\Support\PracticeSetMasterProfile;
use App\Support\PracticeSetTier;
use Closure;

class SetCoverageGrouping
{
    /**
     * Dashboard layout: Learner / Achiever / Expert columns with MCQ · Fill blank · Written · Test rows,
     * plus Formula block and Book content (not split by tier yet).
     *
     * @param  array<string, mixed>  $items
     * @param  Closure(array<string, mixed>): array<string, mixed>|null  $mapItem
     * @return array{
     *     layout: string,
     *     blocks: list<array<string, mixed>>,
     *     formula: array{key: string, label: string, items: list<array<string, mixed>>},
     *     practice_correction: array{key: string, label: string, items: list<array<string, mixed>>},
     *     books: array{key: string, label: string, items: list<array<string, mixed>>}
     * }
     */
    public function formatDashboard(array $items, ?Closure $mapItem = null): array
    {
        $mapItem ??= fn (array $item) => $item;

        $rowDefs = [
            'practice' => 'MCQ',
            'fill_blank' => 'Fill in blank',
            'written' => 'Written',
            'test' => 'Test',
        ];

        $blocks = [];

        foreach (PracticeSetMasterProfile::keys() as $tier) {
            $profile = PracticeSetMasterProfile::profile($tier) ?? [];
            $rows = [];

            foreach ($rowDefs as $key => $label) {
                $rowItems = collect($items[$key] ?? [])
                    ->filter(fn (array $item) => $this->displayTier($item) === $tier)
                    ->map(fn (array $item) => $mapItem($item))
                    ->sort(fn (array $left, array $right) => $this->studiedFirstCompare($left, $right))
                    ->values()
                    ->all();

                $rows[] = [
                    'key' => $key,
                    'label' => $label,
                    'items' => $rowItems,
                ];
            }

            $blocks[] = [
                'tier' => $tier,
                'label' => PracticeSetTier::label($tier),
                'tagline' => PracticeSetTier::tagline($tier),
                'color' => $profile['color'] ?? 'slate',
                'rows' => $rows,
                'item_count' => collect($rows)->sum(fn (array $row) => count($row['items'])),
            ];
        }

        return [
            'layout' => 'tier_blocks',
            'blocks' => $blocks,
            'formula' => [
                'key' => 'formula',
                'label' => 'Formula',
                'items' => collect($items['formula'] ?? [])
                    ->map(fn (array $item) => $mapItem($item))
                    ->sort(fn (array $left, array $right) => $this->studiedFirstCompare($left, $right))
                    ->values()
                    ->all(),
            ],
            'practice_correction' => [
                'key' => 'practice_correction',
                'label' => 'Practice · Correction',
                'items' => collect($items['practice_correction'] ?? [])
                    ->map(fn (array $item) => $mapItem($item))
                    ->sort(fn (array $left, array $right) => $this->studiedFirstCompare($left, $right))
                    ->values()
                    ->all(),
            ],
            'books' => [
                'key' => 'books',
                'label' => 'Book content',
                'items' => collect($items['books'] ?? [])
                    ->flatten(1)
                    ->map(fn (array $item) => $mapItem($item))
                    ->sort(fn (array $left, array $right) => $this->studiedFirstCompare($left, $right))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Flat groups (legacy / tests). Prefer formatDashboard for UI.
     *
     * @param  array<string, mixed>  $items
     * @param  Closure(array<string, mixed>): array<string, mixed>|null  $mapItem
     * @return list<array{key: string, label: string, tier: string|null, color: string|null, kind: string|null, items: list<array<string, mixed>>}>
     */
    public function formatDetailGroups(array $items, ?Closure $mapItem = null): array
    {
        $dashboard = $this->formatDashboard($items, $mapItem);
        $groups = [];

        foreach ($dashboard['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                if ($row['items'] === []) {
                    continue;
                }

                $groups[] = [
                    'key' => "{$block['tier']}:{$row['key']}",
                    'label' => "{$block['label']} · {$row['label']}",
                    'tier' => $block['tier'],
                    'color' => $block['color'],
                    'kind' => $row['key'],
                    'items' => $row['items'],
                ];
            }
        }

        foreach (['formula', 'practice_correction', 'books'] as $sectionKey) {
            $section = $dashboard[$sectionKey];
            if ($section['items'] === []) {
                continue;
            }

            $groups[] = [
                'key' => $section['key'],
                'label' => $section['label'],
                'tier' => null,
                'color' => null,
                'kind' => $section['key'],
                'items' => $section['items'],
            ];
        }

        return $groups;
    }

    /**
     * Map worksheet tier to a display profile. Legacy chapter_test → Learner until reclassified.
     *
     * @param  array<string, mixed>  $item
     */
    private function displayTier(array $item): string
    {
        $tier = (string) ($item['tier'] ?? PracticeSetTier::STARTER);

        if (PracticeSetMasterProfile::isValid($tier)) {
            return $tier;
        }

        return PracticeSetTier::STARTER;
    }

    /**
     * Done / studied sets first, then not done — keeps relative order within each group.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function studiedFirstCompare(array $left, array $right): int
    {
        $rank = static function (array $item): int {
            $status = strtolower((string) ($item['status'] ?? ''));

            if ($status === 'done' || ($item['studied'] ?? false)) {
                return 0;
            }

            return 1;
        };

        return $rank($left) <=> $rank($right);
    }
}
