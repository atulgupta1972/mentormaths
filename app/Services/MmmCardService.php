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
    public function playPayload(MmmCard $card, ?User $learner = null, ?int $exerciseIndex = null): array
    {
        $card->loadMissing('exercises');
        $progress = $learner
            ? StudentMmmProgress::query()
                ->where('user_id', $learner->id)
                ->where('mmm_card_id', $card->id)
                ->first()
            : null;

        $count = $card->exercises->count();
        $maxIndex = max(0, $count - 1);

        if ($exerciseIndex === null) {
            $exerciseIndex = min((int) ($progress?->exercises_completed ?? 0), $maxIndex);
        } else {
            $exerciseIndex = max(0, min($exerciseIndex, $maxIndex));
        }

        $completed = (int) ($progress?->exercises_completed ?? 0);

        return [
            'card' => [
                'id' => $card->id,
                'slug' => $card->slug,
                'title' => $card->title,
                'heading' => $card->heading,
                'accent' => $card->accent ?: 'teal',
                'method_blurb' => $card->method_blurb,
                'table_schema' => $card->table_schema,
                'exercises_total' => $count,
            ],
            'exercise' => $this->exercisePayload($card->exercises[$exerciseIndex] ?? $card->exercises->first()),
            'exercise_index' => $exerciseIndex,
            'has_next' => $exerciseIndex < $maxIndex,
            'all_done' => $count > 0 && $completed >= $count,
            'progress' => [
                'exercises_completed' => $completed,
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

        $answers = is_array($exercise->answers) ? $exercise->answers : [];
        $usesVariable = ($answers['mode'] ?? null) === 'variable'
            || ! empty($answers['variable_tip']);

        return [
            'id' => $exercise->id,
            'difficulty' => $exercise->difficulty,
            'is_demo' => (bool) $exercise->is_demo,
            'question_text' => $exercise->question_text,
            'sort_order' => $exercise->sort_order,
            'uses_variable' => $usesVariable,
            'variable_tip' => $answers['variable_tip'] ?? null,
            'ask_equation' => array_key_exists('equation', $answers),
            'ask_x_value' => array_key_exists('x_value', $answers),
            'x_prompt' => $answers['x_prompt'] ?? 'Value of x (correct answers)',
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
                $ok = $this->valueMatches(
                    $expected[$rowKey][$field] ?? null,
                    data_get($submission, "rows.{$rowKey}.{$field}"),
                );
                $cellResults[$cellKey] = $ok;
                if (! $ok) {
                    $allOk = false;
                }
            }
        }

        if (! empty($schema['show_grand_total'])) {
            $ok = $this->valueMatches($expected['grand_total'] ?? null, $submission['grand_total'] ?? null);
            $cellResults['grand_total'] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        if (array_key_exists('equation', $expected)) {
            $ok = $this->valueMatches($expected['equation'], $submission['equation'] ?? null, isEquation: true);
            $cellResults['equation'] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        if (array_key_exists('x_value', $expected)) {
            $ok = $this->valueMatches($expected['x_value'], $submission['x_value'] ?? null);
            $cellResults['x_value'] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        $asksEquation = array_key_exists('equation', $expected);

        return [
            'ok' => $allOk,
            'cell_results' => $cellResults,
            'message' => $allOk
                ? ($asksEquation
                    ? 'Table and equation look good — well done.'
                    : 'Table looks good — well done.')
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

    private function valueMatches(mixed $expected, mixed $got, bool $isEquation = false): bool
    {
        if ($isEquation) {
            return $this->equationMatches($expected, $got);
        }

        $candidates = is_array($expected) ? $expected : [$expected];
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $wantNum = $this->normalizeNumber($candidate);
            $gotNum = $this->normalizeNumber($got);
            if ($wantNum !== null && $gotNum !== null && $wantNum === $gotNum) {
                return true;
            }

            $wantExpr = $this->normalizeExpression($candidate);
            $gotExpr = $this->normalizeExpression($got);

            if ($wantExpr !== null && $gotExpr !== null && $wantExpr === $gotExpr) {
                return true;
            }
        }

        return false;
    }

    private function equationMatches(mixed $expected, mixed $got): bool
    {
        $gotCanon = $this->equationCanonical($got);
        if ($gotCanon === null) {
            return false;
        }

        $candidates = is_array($expected) ? $expected : [$expected];
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            // Exact normalized string match (legacy).
            $wantExpr = $this->normalizeEquation($candidate);
            $gotExpr = $this->normalizeEquation($got);
            if ($wantExpr !== null && $gotExpr !== null && $wantExpr === $gotExpr) {
                return true;
            }

            // Algebraic equivalence: any form of the same linear equation.
            $wantCanon = $this->equationCanonical($candidate);
            if ($wantCanon !== null && $wantCanon === $gotCanon) {
                return true;
            }
        }

        return false;
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

    private function normalizeExpression(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        // Invisible / odd spaces and unicode lookalikes from phone keyboards.
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $text);
        $text = str_replace(['𝑥', 'ｘ', 'Χ', 'х'], 'x', $text);
        $text = strtolower($text);
        $text = str_replace(['−', '–', '—', '﹣', '－'], '-', $text);
        $text = str_replace(['＝', '﹦'], '=', $text);
        $text = preg_replace('/\s+/u', '', $text) ?? $text;
        $text = str_replace(['×', '*', '⋅'], '', $text);

        // -(20-x) → -20+x
        $text = preg_replace('/-\((\d+)-x\)/', '-$1+x', $text) ?? $text;

        // Standalone x-20 → -20+x
        if (preg_match('/^x-(\d+)$/', $text, $m)) {
            $text = '-'.$m[1].'+x';
        }

        $text = str_replace(['(', ')'], '', $text);
        $text = str_replace(['+-', '-+'], '-', $text);
        // Collapse accidental double signs from typing.
        $text = str_replace(['--', '++'], ['+', '+'], $text);

        return $text;
    }

    private function normalizeEquation(mixed $value): ?string
    {
        $text = $this->normalizeExpression($value);
        if ($text === null || ! str_contains($text, '=')) {
            return $text;
        }

        [$left, $right] = array_pad(explode('=', $text, 2), 2, '');

        // Accept 3x-20=25 and 25=3x-20 as the same.
        $forms = [($left ?? '').'='.($right ?? ''), ($right ?? '').'='.($left ?? '')];
        sort($forms);

        return $forms[0];
    }

    /**
     * Canonical ax+b=0 form for a linear equation in x (sign-normalized).
     *
     * @return array{0: int, 1: int}|null
     */
    private function equationCanonical(mixed $value): ?array
    {
        $text = $this->normalizeExpression($value);
        if ($text === null || ! str_contains($text, '=')) {
            return null;
        }

        [$left, $right] = array_pad(explode('=', $text, 2), 2, '');
        $leftCoeffs = $this->linearCoeffs($left ?? '');
        $rightCoeffs = $this->linearCoeffs($right ?? '');
        if ($leftCoeffs === null || $rightCoeffs === null) {
            return null;
        }

        $a = $leftCoeffs[0] - $rightCoeffs[0];
        $b = $leftCoeffs[1] - $rightCoeffs[1];

        if ($a < 0 || ($a === 0 && $b < 0)) {
            $a = -$a;
            $b = -$b;
        }

        return [$a, $b];
    }

    /**
     * Parse ax+b style expression into [a, b].
     *
     * @return array{0: int, 1: int}|null
     */
    private function linearCoeffs(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return [0, 0];
        }

        if (preg_match('/[^0-9x+\-]/', $expr)) {
            return null;
        }

        if ($expr[0] !== '+' && $expr[0] !== '-') {
            $expr = '+'.$expr;
        }

        if (! preg_match_all('/([+-])(\d*)(x?)/', $expr, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $consumed = '';
        $a = 0;
        $b = 0;

        foreach ($matches as $match) {
            $sign = $match[1] === '-' ? -1 : 1;
            $digits = $match[2];
            $hasX = $match[3] === 'x';

            if ($hasX) {
                $num = $digits === '' ? 1 : (int) $digits;
                $a += $sign * $num;
            } else {
                if ($digits === '') {
                    return null;
                }
                $b += $sign * (int) $digits;
            }

            $consumed .= $match[0];
        }

        if ($consumed !== $expr) {
            return null;
        }

        return [$a, $b];
    }
}
