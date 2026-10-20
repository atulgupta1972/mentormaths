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
        $exercise = $card->exercises[$exerciseIndex] ?? $card->exercises->first();
        $schema = $this->schemaForExercise($card, $exercise);

        return [
            'card' => [
                'id' => $card->id,
                'slug' => $card->slug,
                'title' => $card->title,
                'heading' => $card->heading,
                'accent' => $card->accent ?: 'teal',
                'method_blurb' => $card->method_blurb,
                'table_schema' => $schema,
                'exercises_total' => $count,
            ],
            'exercise' => $this->exercisePayload($exercise),
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
     * @return array<string, mixed>
     */
    public function schemaForExercise(MmmCard $card, ?MmmCardExercise $exercise): array
    {
        $schema = is_array($card->table_schema) ? $card->table_schema : [];
        $answers = is_array($exercise?->answers) ? $exercise->answers : [];
        if (is_array($answers['table_schema'] ?? null)) {
            return $answers['table_schema'];
        }

        return $schema;
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
        $tip = $answers['variable_tip'] ?? $answers['method_tip'] ?? null;

        return [
            'id' => $exercise->id,
            'difficulty' => $exercise->difficulty,
            'is_demo' => (bool) $exercise->is_demo,
            'question_text' => $exercise->question_text,
            'sort_order' => $exercise->sort_order,
            'uses_variable' => $usesVariable,
            'variable_tip' => $tip,
            // Equation is built automatically from the Total column — shown, not typed/checked.
            'show_auto_equation' => $usesVariable || array_key_exists('equation', $answers),
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
        $schema = $this->schemaForExercise($card, $exercise);
        $rows = $schema['rows'] ?? [];
        $editableFields = $this->editableFieldKeys($schema);
        $cellResults = [];
        $allOk = true;

        foreach ($rows as $row) {
            $rowKey = (string) ($row['key'] ?? '');
            if ($rowKey === '') {
                continue;
            }
            $fixedValues = is_array($row['fixed_values'] ?? null) ? $row['fixed_values'] : [];
            foreach ($editableFields as $field) {
                if (array_key_exists($field, $fixedValues)) {
                    continue;
                }
                // Skip fields not present in expected answers for this exercise.
                if (! is_array($expected[$rowKey] ?? null) || ! array_key_exists($field, $expected[$rowKey])) {
                    continue;
                }
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

        if (! empty($schema['show_grand_total']) && array_key_exists('grand_total', $expected)) {
            $ok = $this->valueMatches($expected['grand_total'] ?? null, $submission['grand_total'] ?? null);
            $cellResults['grand_total'] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        if (! empty($schema['show_grand_count']) && array_key_exists('grand_count', $expected)) {
            $ok = $this->valueMatches($expected['grand_count'] ?? null, $submission['grand_count'] ?? null);
            $cellResults['grand_count'] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        // Equation is auto-built in the UI from the Total column — do not mark students wrong for it.

        if (array_key_exists('x_value', $expected)) {
            $ok = $this->valueMatches($expected['x_value'], $submission['x_value'] ?? null);
            $cellResults['x_value'] = $ok;
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

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function editableFieldKeys(array $schema): array
    {
        $fields = [];
        foreach ($schema['columns'] ?? [] as $column) {
            if (! is_array($column)) {
                continue;
            }
            $key = (string) ($column['key'] ?? '');
            if ($key === '' || $key === 'label' || ! empty($column['fixed'])) {
                continue;
            }
            $fields[] = $key;
        }

        // Backward compatible default for older marks cards.
        return $fields !== [] ? $fields : ['marks', 'count', 'total'];
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

    private function valueMatches(mixed $expected, mixed $got): bool
    {
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

    /**
     * Accept any linear equation equivalent to (correct total)+(wrong total)=(score),
     * or to any listed sample form in the exercise answers.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $submission
     */
    private function equationMatchesExpected(array $expected, array $submission): bool
    {
        $got = $submission['equation'] ?? null;
        $gotCanon = $this->equationCanonical($got);
        if ($gotCanon === null) {
            return false;
        }

        // Primary: build target from the (already checked) table totals + score.
        $correctTotal = data_get($submission, 'rows.correct.total');
        $wrongTotal = data_get($submission, 'rows.wrong.total');
        $score = $submission['grand_total'] ?? null;
        if ($correctTotal !== null && $wrongTotal !== null && $score !== null && $score !== '') {
            $fromTable = $this->equationCanonical(
                '('.$correctTotal.')+('.$wrongTotal.')='.$score
            );
            if ($fromTable !== null && $fromTable === $gotCanon) {
                return true;
            }

            // Same with explicit leading + on a negative wrong total: 2x+(-20+x)=25
            $fromTableParen = $this->equationCanonical(
                $correctTotal.'+('.$wrongTotal.')='.$score
            );
            if ($fromTableParen !== null && $fromTableParen === $gotCanon) {
                return true;
            }
        }

        // Fallback: listed acceptable strings in the exercise.
        $candidates = $expected['equation'] ?? [];
        if (! is_array($candidates)) {
            $candidates = [$candidates];
        }
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $wantCanon = $this->equationCanonical($candidate);
            if ($wantCanon !== null && $wantCanon === $gotCanon) {
                return true;
            }
            $wantExpr = $this->normalizeEquation($candidate);
            $gotExpr = $this->normalizeEquation($got);
            if ($wantExpr !== null && $gotExpr !== null && $wantExpr === $gotExpr) {
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
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200D}\x{202F}\x{205F}\x{3000}\x{FEFF}]/u', '', $text) ?? $text;
        $text = str_replace(['𝑥', 'ｘ', 'Χ', 'х', 'Ⅹ'], 'x', $text);
        $text = strtolower($text);
        $text = preg_replace('/\p{Pd}/u', '-', $text) ?? $text; // any dash/minus unicode
        $text = str_replace(['−', '–', '—', '﹣', '－', '­'], '-', $text);
        $text = str_replace(['＝', '﹦', '≟'], '=', $text);
        $text = str_replace(['＋', '﹢'], '+', $text);
        $text = preg_replace('/\s+/u', '', $text) ?? $text;

        // Keep multiplication meaning before dropping the sign: -1*(20-x) and -1(20-x)
        $text = str_replace(['×', '⋅'], '*', $text);
        $text = preg_replace('/(\d)\(/', '$1*(', $text) ?? $text;

        // 6*(x+2) → 6x+12 ; 3*(2-x) → 6-3x
        $text = preg_replace_callback(
            '/(\d+)\*\((x)([+-])(\d+)\)/',
            static function (array $m): string {
                $n = (int) $m[1];
                $sign = $m[3] === '-' ? -1 : 1;
                $c = (int) $m[4];
                $xPart = $n === 1 ? 'x' : $n.'x';
                $constant = $n * $sign * $c;

                return $constant >= 0 ? $xPart.'+'.$constant : $xPart.$constant;
            },
            $text,
        ) ?? $text;
        $text = preg_replace_callback(
            '/(\d+)\*\((\d+)([+-])(x)\)/',
            static function (array $m): string {
                $n = (int) $m[1];
                $a = (int) $m[2];
                $sign = $m[3] === '-' ? -1 : 1;
                $constant = $n * $a;
                $xCoeff = $n * $sign;
                $xPart = $xCoeff === 1 ? '+x' : ($xCoeff === -1 ? '-x' : (($xCoeff > 0 ? '+' : '').$xCoeff.'x'));

                return $constant.$xPart;
            },
            $text,
        ) ?? $text;

        // -1*(20-x) or -(20-x) → -20+x
        $text = preg_replace_callback(
            '/-(\d*)\*\((\d+)-x\)/',
            static function (array $m): string {
                $n = $m[1] === '' ? 1 : (int) $m[1];
                $constant = -1 * $n * (int) $m[2];
                $xPart = $n === 1 ? '+x' : '+'.$n.'x';

                return $constant.$xPart;
            },
            $text,
        ) ?? $text;
        $text = preg_replace('/-\((\d+)-x\)/', '-$1+x', $text) ?? $text;
        // -(x-2) → -x+2
        $text = preg_replace('/-\((x)-(\d+)\)/', '-x+$2', $text) ?? $text;

        $text = str_replace('*', '', $text);

        // Standalone x-20 → -20+x
        if (preg_match('/^x-(\d+)$/', $text, $m)) {
            $text = '-'.$m[1].'+x';
        }

        $text = str_replace(['(', ')'], '', $text);
        $text = str_replace(['+-', '-+'], '-', $text);
        $text = str_replace(['--', '++'], ['+', '+'], $text);
        $text = str_replace(['+1x', '-1x'], ['+x', '-x'], $text);

        // Drop anything that is not part of a linear expression / equation.
        $text = preg_replace('/[^0-9x=+\-]/', '', $text) ?? $text;

        return $text === '' ? null : $text;
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
