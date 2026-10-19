<?php

namespace App\Services;

use App\Models\MmmCard;
use App\Models\MmmCardExercise;
use App\Models\StudentMmmProgress;
use App\Models\SyllabusChapter;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class MmmCardService
{
    /**
     * @param  list<int>  $syllabusChapterIds
     * @return array<int, list<array<string, mixed>>> keyed by syllabus_chapter_id
     */
    public function ctasForSyllabusChapters(?User $learner, array $syllabusChapterIds): array
    {
        if ($syllabusChapterIds === []) {
            return [];
        }

        $cards = MmmCard::query()
            ->where('status', MmmCard::STATUS_PUBLISHED)
            ->whereHas('syllabusChapters', fn ($q) => $q->whereIn('syllabus_chapters.id', $syllabusChapterIds))
            ->with(['syllabusChapters:id', 'exercises:id,mmm_card_id'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($cards->isEmpty()) {
            return [];
        }

        $progress = $learner
            ? StudentMmmProgress::query()
                ->where('user_id', $learner->id)
                ->whereIn('mmm_card_id', $cards->pluck('id'))
                ->get()
                ->keyBy('mmm_card_id')
            : collect();

        $out = [];

        foreach ($cards as $card) {
            $payload = $this->ctaPayload($card, $progress->get($card->id));
            foreach ($card->syllabusChapters as $chapter) {
                if (! in_array((int) $chapter->id, $syllabusChapterIds, true)) {
                    continue;
                }
                $out[(int) $chapter->id][] = $payload;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function ctaPayload(MmmCard $card, ?StudentMmmProgress $progress = null): array
    {
        $total = $card->exercises->count();
        $done = (int) ($progress?->exercises_completed ?? 0);
        $status = 'not_started';
        if ($progress?->isCompleted()) {
            $status = 'completed';
        } elseif ($progress && $done > 0) {
            $status = 'in_progress';
        }

        return [
            'id' => $card->id,
            'slug' => $card->slug,
            'heading' => $card->heading,
            'title' => $card->title,
            'accent' => $card->accent ?: 'teal',
            'exercises_total' => $total,
            'exercises_completed' => $done,
            'status' => $status,
            'play_url' => route('student.mmm-cards.show', $card),
            'admin_url' => route('admin.mmm-cards.show', $card),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function playPayload(MmmCard $card, ?User $learner = null): array
    {
        $card->loadMissing('exercises');
        $progress = $learner
            ? StudentMmmProgress::query()
                ->where('user_id', $learner->id)
                ->where('mmm_card_id', $card->id)
                ->first()
            : null;

        $exerciseIndex = min(
            (int) ($progress?->exercises_completed ?? 0),
            max(0, $card->exercises->count() - 1),
        );

        return [
            'card' => [
                'id' => $card->id,
                'slug' => $card->slug,
                'title' => $card->title,
                'heading' => $card->heading,
                'accent' => $card->accent ?: 'teal',
                'method_blurb' => $card->method_blurb,
                'table_schema' => $card->table_schema,
                'exercises_total' => $card->exercises->count(),
            ],
            'exercise' => $this->exercisePayload($card->exercises[$exerciseIndex] ?? $card->exercises->first()),
            'exercise_index' => $exerciseIndex,
            'progress' => [
                'exercises_completed' => (int) ($progress?->exercises_completed ?? 0),
                'status' => $progress?->status ?? StudentMmmProgress::STATUS_NOT_STARTED,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function exercisePayload(?MmmCardExercise $exercise): ?array
    {
        if (! $exercise) {
            return null;
        }

        return [
            'id' => $exercise->id,
            'difficulty' => $exercise->difficulty,
            'is_demo' => (bool) $exercise->is_demo,
            'question_text' => $exercise->question_text,
            'sort_order' => $exercise->sort_order,
        ];
    }

    /**
     * @param  array<string, mixed>  $submission
     * @return array{ok: bool, cell_results: array<string, bool>, message: string}
     */
    public function checkSubmission(MmmCard $card, MmmCardExercise $exercise, array $submission): array
    {
        if ((int) $exercise->mmm_card_id !== (int) $card->id) {
            throw new InvalidArgumentException('Exercise does not belong to this card.');
        }

        $expected = $exercise->answers ?? [];
        $schema = $card->table_schema ?? [];
        $rows = $schema['rows'] ?? [];
        $cellResults = [];
        $allOk = true;

        foreach ($rows as $row) {
            $rowKey = (string) ($row['key'] ?? '');
            if ($rowKey === '') {
                continue;
            }
            foreach (['marks', 'count', 'total'] as $field) {
                $cellKey = "{$rowKey}.{$field}";
                $want = $this->normalizeNumber($expected[$rowKey][$field] ?? null);
                $got = $this->normalizeNumber(data_get($submission, "rows.{$rowKey}.{$field}"));
                $ok = $want !== null && $got !== null && $want === $got;
                $cellResults[$cellKey] = $ok;
                if (! $ok) {
                    $allOk = false;
                }
            }
        }

        if (! empty($schema['show_grand_total'])) {
            $want = $this->normalizeNumber($expected['grand_total'] ?? null);
            $got = $this->normalizeNumber($submission['grand_total'] ?? null);
            $ok = $want !== null && $got !== null && $want === $got;
            $cellResults['grand_total'] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        return [
            'ok' => $allOk,
            'cell_results' => $cellResults,
            'message' => $allOk
                ? 'Table looks good — well done.'
                : 'Some cells are wrong (highlighted). Fix them and submit again.',
        ];
    }

    public function recordExercisePassed(User $user, MmmCard $card, MmmCardExercise $exercise): StudentMmmProgress
    {
        $card->loadMissing('exercises');
        $orderedIds = $card->exercises->pluck('id')->values();
        $index = $orderedIds->search((int) $exercise->id);
        if ($index === false) {
            throw new InvalidArgumentException('Exercise not on this card.');
        }

        $completedThrough = (int) $index + 1;
        $total = $orderedIds->count();
        $existing = StudentMmmProgress::query()
            ->where('user_id', $user->id)
            ->where('mmm_card_id', $card->id)
            ->first();
        $exercisesCompleted = max($completedThrough, (int) ($existing?->exercises_completed ?? 0));
        $done = $exercisesCompleted >= $total;

        return StudentMmmProgress::query()->updateOrCreate(
            ['user_id' => $user->id, 'mmm_card_id' => $card->id],
            [
                'exercises_completed' => $exercisesCompleted,
                'status' => $done
                    ? StudentMmmProgress::STATUS_COMPLETED
                    : StudentMmmProgress::STATUS_IN_PROGRESS,
                'completed_at' => $done ? ($existing?->completed_at ?? now()) : null,
            ],
        );
    }

    /**
     * @param  list<int>  $syllabusChapterIds
     */
    public function syncChapterLinks(MmmCard $card, array $syllabusChapterIds): void
    {
        $ids = collect($syllabusChapterIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $valid = SyllabusChapter::query()->whereIn('id', $ids)->pluck('id')->all();
        $card->syllabusChapters()->sync($valid);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function chapterOptionsForPicker(?int $boardId, ?int $gradeLevelId): Collection
    {
        if (! $boardId || ! $gradeLevelId) {
            return collect();
        }

        return SyllabusChapter::query()
            ->with(['syllabusVersion.board:id,code,name', 'syllabusVersion.gradeLevel:id,name'])
            ->whereHas('syllabusVersion', fn ($q) => $q
                ->where('board_id', $boardId)
                ->where('grade_level_id', $gradeLevelId))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (SyllabusChapter $chapter) => [
                'id' => $chapter->id,
                'label' => trim(($chapter->chapter_number ? $chapter->chapter_number.' · ' : '').$chapter->name),
                'board' => $chapter->syllabusVersion?->board?->code,
                'class' => $chapter->syllabusVersion?->gradeLevel?->name,
            ]);
    }

    private function normalizeNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) $value);
        $text = str_replace([',', ' '], '', $text);
        $text = str_replace(['−', '–'], '-', $text);

        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        $number = (float) $text;
        if (abs($number - round($number)) < 0.00001) {
            return (string) (int) round($number);
        }

        return rtrim(rtrim(sprintf('%.6F', $number), '0'), '.');
    }
}
