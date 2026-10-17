<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ChapterHead;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\TextbookChapter;
use App\Support\ConceptBranch;
use App\Support\ConceptPathStatus;
use Illuminate\Support\Collection;

/**
 * Cross-class concept map: chapter heads × class × board, with concept-path readiness.
 */
class ConceptOverviewService
{
    /**
     * @return array{
     *     year: array{id: int, name: string}|null,
     *     classes: list<array{id: int, name: string, number: int, sort_order: int}>,
     *     boards: list<array{id: int, code: string, name: string}>,
     *     branch_options: list<array{key: string, label: string}>,
     *     branches: list<array{key: string, label: string, rows: list<array<string, mixed>>}>,
     *     rows: list<array<string, mixed>>,
     *     summary: array{heads: int, ready: int, draft: int, to_upload: int, practice_only: int}
     * }
     */
    public function matrix(?int $yearId = null, ?int $boardId = null, ?string $branchKey = null): array
    {
        $year = $this->resolveYear($yearId);
        $boards = $this->boards();
        $classes = $this->classes();
        $branchFilter = $branchKey !== null && $branchKey !== ''
            ? ConceptBranch::normalize($branchKey)
            : null;

        // Include draft + published so the map matches what you see in the syllabus editor.
        $chapters = $this->loadChapters($year?->id, taggedOnly: true);
        $allYearChapters = $this->loadChapters($year?->id, taggedOnly: false);
        $conceptBySyllabus = $this->conceptPathsBySyllabusChapter($chapters->pluck('id')->all());
        $practiceCounts = $this->practiceCountsBySyllabusChapter($chapters->pluck('id')->all());

        $heads = ChapterHead::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $summary = [
            'heads' => 0,
            'ready' => 0,
            'draft' => 0,
            'to_upload' => 0,
            'practice_only' => 0,
            'chapters_total' => 0,
            'chapters_tagged' => 0,
            'chapters_untagged' => 0,
            'chapters_on_map' => 0,
            'matched' => false,
        ];

        $rows = [];
        $mappedChapterIds = [];

        foreach ($heads as $head) {
            $branch = ConceptBranch::normalize($head->branch ?: ConceptBranch::guessFromName((string) $head->name));
            if ($branchFilter !== null && $branch !== $branchFilter) {
                continue;
            }

            $headChapters = $chapters->where('chapter_head_id', $head->id)->values();
            if ($headChapters->isEmpty()) {
                continue;
            }

            // Keep only Class 4–10 maths rows for this map.
            $headChapters = $headChapters->filter(function (SyllabusChapter $ch) use ($classes) {
                $n = $this->classNumber($ch->syllabusVersion?->gradeLevel);

                return $n >= 4 && $n <= 10 && collect($classes)->contains(fn ($c) => (int) $c['number'] === $n);
            })->values();

            if ($headChapters->isEmpty()) {
                continue;
            }

            $summary['heads']++;
            $cells = [];

            foreach ($classes as $class) {
                $classChapters = $headChapters->filter(
                    fn (SyllabusChapter $ch) => $this->classNumber($ch->syllabusVersion?->gradeLevel) === (int) $class['number']
                );

                if ($boardId) {
                    $classChapters = $classChapters->filter(
                        fn (SyllabusChapter $ch) => (int) ($ch->syllabusVersion?->board_id) === (int) $boardId
                    );
                }

                $byBoard = [];
                foreach ($boards as $board) {
                    if ($boardId && (int) $board['id'] !== (int) $boardId) {
                        continue;
                    }

                    $boardMatches = $classChapters
                        ->filter(fn (SyllabusChapter $ch) => (int) ($ch->syllabusVersion?->board_id) === (int) $board['id'])
                        ->sortByDesc(fn (SyllabusChapter $ch) => $ch->syllabusVersion?->status === SyllabusVersion::STATUS_PUBLISHED ? 1 : 0)
                        ->values();

                    foreach ($boardMatches as $match) {
                        $mappedChapterIds[(int) $match->id] = true;
                    }

                    $boardChapter = $boardMatches->first();

                    $cell = $this->buildCell(
                        $boardChapter,
                        $board,
                        $conceptBySyllabus,
                        $practiceCounts,
                    );
                    $byBoard[$board['code']] = $cell;
                    $this->tallySummary($summary, $cell);
                }

                $cells[(string) $class['number']] = [
                    'class_number' => $class['number'],
                    'class_name' => $class['name'],
                    'boards' => $byBoard,
                    'best_status' => $this->bestStatus(array_column($byBoard, 'status')),
                ];
            }

            $rows[] = [
                'id' => $head->id,
                'name' => $head->name,
                'branch' => $branch,
                'branch_label' => ConceptBranch::label($branch),
                'sort_order' => $head->sort_order,
                'cells' => $cells,
                'url' => route('admin.concept-overview.show', $head),
            ];
        }

        $branches = [];
        foreach (ConceptBranch::keys() as $key) {
            $branchRows = array_values(array_filter($rows, fn (array $row) => $row['branch'] === $key));
            if ($branchRows === []) {
                continue;
            }
            $branches[] = [
                'key' => $key,
                'label' => ConceptBranch::label($key),
                'rows' => $branchRows,
            ];
        }

        $coverage = $this->coverageTally(
            $allYearChapters,
            $classes,
            $boardId,
            $mappedChapterIds,
            $branchFilter,
        );
        $summary['chapters_total'] = $coverage['chapters_total'];
        $summary['chapters_tagged'] = $coverage['chapters_tagged'];
        $summary['chapters_untagged'] = $coverage['chapters_untagged'];
        $summary['chapters_on_map'] = $coverage['chapters_on_map'];
        $summary['matched'] = $coverage['matched'];

        return [
            'year' => $year?->only(['id', 'name']),
            'classes' => $classes,
            'boards' => $boardId
                ? array_values(array_filter($boards, fn ($b) => (int) $b['id'] === (int) $boardId))
                : $boards,
            'branch_options' => ConceptBranch::options(),
            'branches' => $branches,
            'rows' => $rows,
            'summary' => $summary,
            'untagged_chapters' => $coverage['untagged_list'],
            'orphan_tagged_chapters' => $coverage['orphan_tagged_list'],
        ];
    }

    /**
     * Progressive drill-down for one chapter head across classes/boards.
     *
     * @return array<string, mixed>
     */
    public function progression(ChapterHead $head, ?int $yearId = null): array
    {
        $year = $this->resolveYear($yearId);
        $boards = $this->boards();
        $classes = $this->classes();

        $chapters = $this->loadChapters($year?->id, taggedOnly: true)
            ->where('chapter_head_id', $head->id)
            ->values();

        $conceptBySyllabus = $this->conceptPathsBySyllabusChapter($chapters->pluck('id')->all());
        $practiceCounts = $this->practiceCountsBySyllabusChapter($chapters->pluck('id')->all());

        $ladder = [];
        foreach ($classes as $class) {
            $classChapters = $chapters->filter(
                fn (SyllabusChapter $ch) => $this->classNumber($ch->syllabusVersion?->gradeLevel) === (int) $class['number']
            );
            if ($classChapters->isEmpty()) {
                continue;
            }

            $boardCards = [];
            foreach ($boards as $board) {
                $chapter = $classChapters
                    ->filter(fn (SyllabusChapter $ch) => (int) ($ch->syllabusVersion?->board_id) === (int) $board['id'])
                    ->sortByDesc(fn (SyllabusChapter $ch) => $ch->syllabusVersion?->status === SyllabusVersion::STATUS_PUBLISHED ? 1 : 0)
                    ->first();
                if (! $chapter) {
                    continue;
                }

                $cell = $this->buildCell($chapter, $board, $conceptBySyllabus, $practiceCounts);
                $topics = $chapter->topics
                    ->sortBy('sort_order')
                    ->values()
                    ->map(fn ($topic) => [
                        'id' => $topic->id,
                        'name' => $topic->name,
                        'difficulty' => $topic->difficulty,
                        'practice_url' => route('admin.practice-sets.topics.show', $topic),
                    ])
                    ->all();

                $boardCards[] = array_merge($cell, [
                    'topics' => $topics,
                    'topic_count' => count($topics),
                ]);
            }

            if ($boardCards === []) {
                continue;
            }

            $ladder[] = [
                'class_number' => $class['number'],
                'class_name' => $class['name'],
                'boards' => $boardCards,
                'best_status' => $this->bestStatus(array_column($boardCards, 'status')),
            ];
        }

        $branch = ConceptBranch::normalize($head->branch ?: ConceptBranch::guessFromName((string) $head->name));

        return [
            'year' => $year?->only(['id', 'name']),
            'chapter_head' => [
                'id' => $head->id,
                'name' => $head->name,
                'branch' => $branch,
                'branch_label' => ConceptBranch::label($branch),
            ],
            'boards' => $boards,
            'ladder' => $ladder,
            'compare_hint' => 'Same concept across boards — use this to show parents what ICSE vs CBSE covers at each class, and to stretch a strong student into the next rung.',
        ];
    }

    private function resolveYear(?int $yearId): ?AcademicYear
    {
        if ($yearId) {
            return AcademicYear::query()->find($yearId);
        }

        return AcademicYear::active();
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    private function boards(): array
    {
        return Board::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Board $b) => [
                'id' => $b->id,
                'code' => (string) $b->code,
                'name' => (string) $b->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, number: int, sort_order: int}>
     */
    private function classes(): array
    {
        $grades = GradeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $grades
            ->groupBy(fn (GradeLevel $grade) => $this->classNumber($grade))
            ->filter(fn ($group, $number) => (int) $number >= 4 && (int) $number <= 10)
            ->map(function ($group, $number) {
                $preferred = $this->preferredGradeForClass($group);

                return [
                    'id' => $preferred->id,
                    'name' => (string) $preferred->name,
                    'number' => (int) $number,
                    'sort_order' => (int) $preferred->sort_order,
                ];
            })
            ->sortBy('number')
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, GradeLevel>  $grades
     */
    private function preferredGradeForClass($grades): GradeLevel
    {
        $list = $grades instanceof Collection ? $grades->values() : collect($grades)->values();

        $canonical = $list->first(function (GradeLevel $grade) {
            $n = $this->classNumber($grade);

            return (bool) preg_match('/^class\s*'.$n.'$/i', trim((string) $grade->name));
        });

        return $canonical ?: $list->sortBy('id')->first();
    }

    private function classNumber(?GradeLevel $grade): int
    {
        if (! $grade) {
            return 0;
        }

        if (preg_match('/(\d+)/', (string) $grade->name, $m)) {
            return (int) $m[1];
        }

        return (int) ($grade->sort_order ?: 0);
    }

    /**
     * @return Collection<int, SyllabusChapter>
     */
    private function loadChapters(?int $yearId, bool $taggedOnly = true): Collection
    {
        $mathsIds = Subject::query()
            ->where(function ($q) {
                $q->where('code', 'MATHS')
                    ->orWhere('name', 'like', 'Math%');
            })
            ->pluck('id')
            ->all();

        return SyllabusChapter::query()
            ->with([
                'chapterHead',
                'topics',
                'syllabusVersion.board',
                'syllabusVersion.gradeLevel',
                'syllabusVersion.subject',
            ])
            ->when($taggedOnly, fn ($q) => $q->whereNotNull('chapter_head_id'))
            ->whereHas('syllabusVersion', function ($vq) use ($yearId, $mathsIds) {
                // Draft + published — same chapters you see while editing the syllabus.
                $vq->whereIn('status', [
                    SyllabusVersion::STATUS_PUBLISHED,
                    SyllabusVersion::STATUS_DRAFT,
                ]);
                if ($yearId) {
                    $vq->where('academic_year_id', $yearId);
                }
                if ($mathsIds !== []) {
                    $vq->whereIn('subject_id', $mathsIds);
                }
            })
            ->get();
    }

    /**
     * @param  Collection<int, SyllabusChapter>  $allYearChapters
     * @param  list<array{id: int, name: string, number: int, sort_order: int}>  $classes
     * @param  array<int, true>  $mappedChapterIds
     * @return array{
     *     chapters_total: int,
     *     chapters_tagged: int,
     *     chapters_untagged: int,
     *     chapters_on_map: int,
     *     matched: bool,
     *     untagged_list: list<array<string, mixed>>,
     *     orphan_tagged_list: list<array<string, mixed>>
     * }
     */
    private function coverageTally(
        Collection $allYearChapters,
        array $classes,
        ?int $boardId,
        array $mappedChapterIds,
        ?string $branchFilter = null,
    ): array {
        $classNumbers = collect($classes)->pluck('number')->map(fn ($n) => (int) $n)->all();

        $inScope = $allYearChapters->filter(function (SyllabusChapter $ch) use ($classNumbers, $boardId) {
            $n = $this->classNumber($ch->syllabusVersion?->gradeLevel);
            if (! in_array($n, $classNumbers, true)) {
                return false;
            }
            if ($boardId && (int) ($ch->syllabusVersion?->board_id) !== (int) $boardId) {
                return false;
            }

            return true;
        })->values();

        $tagged = $inScope->filter(fn (SyllabusChapter $ch) => $ch->chapter_head_id !== null)->values();
        $untagged = $inScope->filter(fn (SyllabusChapter $ch) => $ch->chapter_head_id === null)->values();

        if ($branchFilter !== null) {
            $tagged = $tagged->filter(function (SyllabusChapter $ch) use ($branchFilter) {
                $branch = ConceptBranch::normalize(
                    $ch->chapterHead?->branch ?: ConceptBranch::guessFromName((string) ($ch->chapterHead?->name ?? ''))
                );

                return $branch === $branchFilter;
            })->values();
        }

        $onMap = $tagged->filter(fn (SyllabusChapter $ch) => isset($mappedChapterIds[(int) $ch->id]))->count();
        $orphanTagged = $tagged->filter(fn (SyllabusChapter $ch) => ! isset($mappedChapterIds[(int) $ch->id]));

        $format = fn (SyllabusChapter $ch) => [
            'id' => $ch->id,
            'name' => $ch->name,
            'chapter_number' => $ch->chapter_number,
            'chapter_head' => $ch->chapterHead?->name,
            'board_code' => $ch->syllabusVersion?->board?->code,
            'grade_name' => $ch->syllabusVersion?->gradeLevel?->name,
            'status' => $ch->syllabusVersion?->status,
            'syllabus_url' => $ch->syllabus_version_id
                ? route('admin.syllabus.show', $ch->syllabus_version_id)
                : null,
        ];

        $fullTagged = $inScope->filter(fn (SyllabusChapter $ch) => $ch->chapter_head_id !== null);

        if ($branchFilter === null) {
            $matched = $untagged->isEmpty()
                && $orphanTagged->isEmpty()
                && $inScope->isNotEmpty()
                && $onMap === $tagged->count();
        } else {
            $matched = $orphanTagged->isEmpty() && $onMap === $tagged->count();
        }

        return [
            'chapters_total' => $inScope->count(),
            'chapters_tagged' => $branchFilter === null ? $fullTagged->count() : $tagged->count(),
            'chapters_untagged' => $untagged->count(),
            'chapters_on_map' => $onMap,
            'matched' => $matched,
            'untagged_list' => $untagged->take(50)->map($format)->values()->all(),
            'orphan_tagged_list' => $orphanTagged->take(50)->map($format)->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $syllabusChapterIds
     * @return array<int, array{id: int, status: string|null, title: string, concept_url: string|null, play_url: string|null}>
     */
    private function conceptPathsBySyllabusChapter(array $syllabusChapterIds): array
    {
        if ($syllabusChapterIds === []) {
            return [];
        }

        $map = [];
        TextbookChapter::query()
            ->whereIn('syllabus_chapter_id', $syllabusChapterIds)
            ->orderByDesc('id')
            ->get()
            ->each(function (TextbookChapter $tb) use (&$map) {
                $sid = (int) $tb->syllabus_chapter_id;
                if (isset($map[$sid])) {
                    return;
                }

                $hasItems = is_array($tb->concept_path_items) && $tb->concept_path_items !== [];
                $status = $tb->concept_path_status;
                if (! $hasItems && ! $status) {
                    $status = null;
                }

                $map[$sid] = [
                    'id' => $tb->id,
                    'status' => $status,
                    'title' => (string) $tb->title,
                    'concept_url' => route('admin.textbooks.concept-path', $tb),
                    'play_url' => $status === ConceptPathStatus::APPROVED
                        ? route('admin.textbooks.concept-path.play', $tb)
                        : null,
                ];
            });

        return $map;
    }

    /**
     * @param  list<int>  $syllabusChapterIds
     * @return array<int, int>
     */
    private function practiceCountsBySyllabusChapter(array $syllabusChapterIds): array
    {
        if ($syllabusChapterIds === []) {
            return [];
        }

        return SyllabusChapter::query()
            ->whereIn('id', $syllabusChapterIds)
            ->withCount('chapterPracticeSets')
            ->get()
            ->mapWithKeys(fn (SyllabusChapter $ch) => [(int) $ch->id => (int) $ch->chapter_practice_sets_count])
            ->all();
    }

    /**
     * @param  array{id: int, code: string, name: string}  $board
     * @param  array<int, array<string, mixed>>  $conceptBySyllabus
     * @param  array<int, int>  $practiceCounts
     * @return array<string, mixed>
     */
    private function buildCell(
        ?SyllabusChapter $chapter,
        array $board,
        array $conceptBySyllabus,
        array $practiceCounts,
    ): array {
        if (! $chapter) {
            return [
                'present' => false,
                'status' => 'empty',
                'status_label' => '—',
                'board_code' => $board['code'],
                'chapter_id' => null,
                'chapter_name' => null,
                'chapter_number' => null,
                'practice_sets' => 0,
                'concept_url' => null,
                'play_url' => null,
                'syllabus_url' => null,
            ];
        }

        $sid = (int) $chapter->id;
        $concept = $conceptBySyllabus[$sid] ?? null;
        $practice = (int) ($practiceCounts[$sid] ?? 0);
        $status = 'to_upload';
        $label = 'To be uploaded';

        if ($concept && ($concept['status'] ?? null) === ConceptPathStatus::APPROVED) {
            $status = 'ready';
            $label = 'Concept ready';
        } elseif ($concept && ($concept['status'] ?? null) === ConceptPathStatus::DRAFT) {
            $status = 'draft';
            $label = 'Concept draft';
        } elseif ($concept && is_array($concept) && ($concept['status'] ?? null)) {
            $status = 'draft';
            $label = ConceptPathStatus::label($concept['status']);
        } elseif ($practice > 0) {
            $status = 'practice_only';
            $label = 'Practice only';
        }

        return [
            'present' => true,
            'status' => $status,
            'status_label' => $label,
            'board_code' => $board['code'],
            'chapter_id' => $chapter->id,
            'chapter_name' => $chapter->name,
            'chapter_number' => $chapter->chapter_number,
            'practice_sets' => $practice,
            'concept_url' => $concept['concept_url'] ?? null,
            'play_url' => $concept['play_url'] ?? null,
            'syllabus_url' => route('admin.syllabus.show', $chapter->syllabus_version_id),
        ];
    }

    /**
     * @param  list<string>  $statuses
     */
    private function bestStatus(array $statuses): string
    {
        $order = ['ready' => 4, 'draft' => 3, 'practice_only' => 2, 'to_upload' => 1, 'empty' => 0];
        $best = 'empty';
        $bestScore = -1;
        foreach ($statuses as $status) {
            $score = $order[$status] ?? 0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $status;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, int>  $summary
     * @param  array<string, mixed>  $cell
     */
    private function tallySummary(array &$summary, array $cell): void
    {
        if (! ($cell['present'] ?? false)) {
            return;
        }

        match ($cell['status'] ?? '') {
            'ready' => $summary['ready']++,
            'draft' => $summary['draft']++,
            'practice_only' => $summary['practice_only']++,
            'to_upload' => $summary['to_upload']++,
            default => null,
        };
    }
}
