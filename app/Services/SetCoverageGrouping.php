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
     *     books: array{key: string, label: string, items: list<array<string, mixed>>},
     *     book_groups: list<array{id: string, name: string, items: list<array<string, mixed>>}>,
     *     other: array{key: string, label: string, items: list<array<string, mixed>>},
     *     other_groups: list<array{id: string, label: string, items: list<array<string, mixed>>}>
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

        $bookGroups = $this->formatBookGroups($items['books'] ?? [], $items['revisions'] ?? [], $mapItem);
        $otherGroups = $this->formatOtherGroups(
            $items['other'] ?? $items['other_groups'] ?? [],
            $mapItem,
        );

        $revisionItems = collect($items['revisions'] ?? [])
            ->map(fn (array $item) => $mapItem($item))
            ->sort(fn (array $left, array $right) => $this->studiedFirstCompare($left, $right))
            ->values()
            ->all();

        $revisionsByWorksheet = collect($revisionItems)->groupBy(fn (array $item) => (string) ($item['worksheet_id'] ?? ''));

        foreach ($blocks as &$block) {
            foreach ($block['rows'] as &$row) {
                $rowRevisionItems = [];
                foreach ($row['items'] as $item) {
                    $worksheetId = (string) ($item['worksheet_id'] ?? '');
                    foreach ($revisionsByWorksheet->get($worksheetId, collect()) as $revision) {
                        // Skip book-tagged revisions here — those render under book groups.
                        if (! empty($revision['textbook_id'])) {
                            continue;
                        }
                        $rowRevisionItems[] = $revision;
                    }
                }
                $row['revision_items'] = $rowRevisionItems;
            }
            unset($row);
        }
        unset($block);

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
                'items' => [],
            ],
            'revisions' => [
                'key' => 'revisions',
                'label' => 'Revision',
                'items' => $revisionItems,
            ],
            'books' => [
                'key' => 'books',
                'label' => 'Book content',
                'items' => collect($bookGroups)->flatMap(fn (array $group) => $group['items'])->values()->all(),
            ],
            'book_groups' => $bookGroups,
            'other' => [
                'key' => 'other',
                'label' => 'Other',
                'items' => collect($otherGroups)->flatMap(fn (array $group) => $group['items'])->values()->all(),
            ],
            'other_groups' => $otherGroups,
        ];
    }

    /**
     * @param  array<string, mixed>  $books
     * @param  list<array<string, mixed>>  $revisions
     * @param  Closure(array<string, mixed>): array<string, mixed>  $mapItem
     * @return list<array{id: string, name: string, items: list<array<string, mixed>>, revision_items: list<array<string, mixed>>}>
     */
    private function formatBookGroups(array $books, array $revisions, Closure $mapItem): array
    {
        $revisionsByWorksheet = collect($revisions)
            ->filter(fn ($item) => is_array($item))
            ->groupBy(fn (array $item) => (string) ($item['worksheet_id'] ?? ''));

        $groups = [];

        foreach ($books as $bookId => $bookItems) {
            if (! is_array($bookItems)) {
                continue;
            }

            $rows = collect($bookItems)
                ->filter(fn ($item) => is_array($item))
                ->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $first = $rows->first();
            $mapped = $rows
                ->map(fn (array $item) => $mapItem($item))
                ->sort(fn (array $left, array $right) => $this->studiedFirstCompare($left, $right))
                ->values()
                ->all();

            $revisionItems = [];
            foreach ($mapped as $item) {
                $worksheetId = (string) ($item['worksheet_id'] ?? '');
                foreach ($revisionsByWorksheet->get($worksheetId, collect()) as $revision) {
                    $revisionItems[] = $mapItem($revision);
                }
            }

            $groups[] = [
                'id' => (string) ($first['textbook_id'] ?? $bookId),
                'name' => (string) ($first['textbook_name'] ?? 'Book content'),
                'items' => $mapped,
                'revision_items' => $revisionItems,
            ];
        }

        return $groups;
    }

    /**
     * @param  array<int, mixed>  $other
     * @param  Closure(array<string, mixed>): array<string, mixed>  $mapItem
     * @return list<array{id: string, label: string, items: list<array<string, mixed>>}>
     */
    private function formatOtherGroups(array $other, Closure $mapItem): array
    {
        $groups = [];

        foreach ($other as $index => $group) {
            if (! is_array($group)) {
                continue;
            }

            $rows = collect($group['items'] ?? [])
                ->filter(fn ($item) => is_array($item))
                ->map(fn (array $item) => $mapItem($item))
                ->sort(fn (array $left, array $right) => $this->studiedFirstCompare($left, $right))
                ->values()
                ->all();

            if ($rows === []) {
                continue;
            }

            $groups[] = [
                'id' => (string) ($group['id'] ?? $group['syllabus_chapter_id'] ?? $index),
                'label' => (string) ($group['label'] ?? $group['name'] ?? 'Other'),
                'grade_name' => $group['grade_name'] ?? null,
                'board_name' => $group['board_name'] ?? null,
                'chapter_name' => $group['chapter_name'] ?? null,
                'syllabus_chapter_id' => $group['syllabus_chapter_id'] ?? null,
                'items' => $rows,
            ];
        }

        return $groups;
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

        foreach ($dashboard['other_groups'] ?? [] as $otherGroup) {
            if (($otherGroup['items'] ?? []) === []) {
                continue;
            }

            $groups[] = [
                'key' => 'other:'.($otherGroup['id'] ?? 'x'),
                'label' => 'Other · '.($otherGroup['label'] ?? 'Extra'),
                'tier' => null,
                'color' => null,
                'kind' => 'other',
                'items' => $otherGroup['items'],
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
