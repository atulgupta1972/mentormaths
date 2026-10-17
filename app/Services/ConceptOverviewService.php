<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ChapterHead;
use App\Models\GradeLevel;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\TextbookChapter;
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
     *     rows: list<array<string, mixed>>,
     *     summary: array{heads: int, ready: int, draft: int, to_upload: int, practice_only: int}
     * }
     */
    public function matrix(?int $yearId = null, ?int $boardId = null): array
    {
        $year = $this->resolveYear($yearId);
        $boards = $this->boards();
        $classes = $this->classes();

        $chapters = $this->loadChapters($year?->id);
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
        ];

        $rows = [];
        foreach ($heads as $head) {
            $headChapters = $chapters->where('chapter_head_id', $head->id);
            if ($headChapters->isEmpty()) {
                continue;
            }

            $summary['heads']++;
            $cells = [];

            foreach ($classes as $class) {
                $classChapters = $headChapters->filter(
                    fn (SyllabusChapter $ch) => (int) ($ch->syllabusVersion?->grade_level_id) === (int) $class['id']
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

                    $boardChapter = $classChapters->first(
                        fn (SyllabusChapter $ch) => (int) ($ch->syllabusVersion?->board_id) === (int) $board['id']
                    );

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
                'sort_order' => $head->sort_order,
                'cells' => $cells,
                'url' => route('admin.concept-overview.show', $head),
            ];
        }

        return [
            'year' => $year?->only(['id', 'name']),
            'classes' => $classes,
            'boards' => $boardId
                ? array_values(array_filter($boards, fn ($b) => (int) $b['id'] === (int) $boardId))
                : $boards,
            'rows' => $rows,
            'summary' => $summary,
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

        $chapters = $this->loadChapters($year?->id)
            ->where('chapter_head_id', $head->id)
            ->values();

        $conceptBySyllabus = $this->conceptPathsBySyllabusChapter($chapters->pluck('id')->all());
        $practiceCounts = $this->practiceCountsBySyllabusChapter($chapters->pluck('id')->all());

        $ladder = [];
        foreach ($classes as $class) {
            $classChapters = $chapters->filter(
                fn (SyllabusChapter $ch) => (int) ($ch->syllabusVersion?->grade_level_id) === (int) $class['id']
            );
            if ($classChapters->isEmpty()) {
                continue;
            }

            $boardCards = [];
            foreach ($boards as $board) {
                $chapter = $classChapters->first(
                    fn (SyllabusChapter $ch) => (int) ($ch->syllabusVersion?->board_id) === (int) $board['id']
                );
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

        return [
            'year' => $year?->only(['id', 'name']),
            'chapter_head' => $head->only(['id', 'name']),
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
        return GradeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (GradeLevel $grade) {
                $number = 0;
                if (preg_match('/(\d+)/', (string) $grade->name, $m)) {
                    $number = (int) $m[1];
                }

                return [
                    'id' => $grade->id,
                    'name' => (string) $grade->name,
                    'number' => $number > 0 ? $number : (int) $grade->sort_order,
                    'sort_order' => (int) $grade->sort_order,
                ];
            })
            ->filter(fn (array $c) => $c['number'] >= 4 && $c['number'] <= 10)
            ->unique('number')
            ->sortBy('number')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, SyllabusChapter>
     */
    private function loadChapters(?int $yearId): Collection
    {
        return SyllabusChapter::query()
            ->with([
                'chapterHead',
                'topics',
                'syllabusVersion.board',
                'syllabusVersion.gradeLevel',
            ])
            ->whereNotNull('chapter_head_id')
            ->when($yearId, fn ($q) => $q->whereHas('syllabusVersion', fn ($vq) => $vq
                ->where('academic_year_id', $yearId)
                ->where('status', SyllabusVersion::STATUS_PUBLISHED)))
            ->when(! $yearId, fn ($q) => $q->whereHas('syllabusVersion', fn ($vq) => $vq
                ->where('status', SyllabusVersion::STATUS_PUBLISHED)))
            ->get();
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
