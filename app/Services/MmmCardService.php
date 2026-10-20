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
        $askEquation = ! empty($answers['check_equation']);

        return [
            'id' => $exercise->id,
            'difficulty' => $exercise->difficulty,
            'is_demo' => (bool) $exercise->is_demo,
            'question_text' => $exercise->question_text,
            'sort_order' => $exercise->sort_order,
            'uses_variable' => $usesVariable,
            'variable_tip' => $tip,
            // Marks: auto from Total column. Money ratio/conditions: student types the equation.
            'show_auto_equation' => ! $askEquation && ($usesVariable || array_key_exists('equation', $answers)),
            'ask_equation' => $askEquation,
            'equation_prompt' => $answers['equation_prompt'] ?? 'Write the equation',
            'ask_x_value' => array_key_exists('x_value', $answers),
            'x_prompt' => $answers['x_prompt'] ?? 'Value of x',
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

        if (! empty($expected['check_equation'])) {
            $submission['equation'] = $this->extractSubmittedEquation($submission);
            $ok = $this->equationMatchesExpected($expected, $submission);
            // If the typed field was stripped/unreadable in transit but Coins (in x) + total
            // coins already match, accept. Do not accept a clearly wrong typed equation.
            if (! $ok && $this->moneyCountXTableProvesEquation($expected, $submission)) {
                $typed = $submission['equation'] ?? null;
                $typedCanon = $this->equationCanonical($typed);
                if ($typed === null || trim((string) $typed) === '' || $typedCanon === null) {
                    $ok = true;
                }
            }
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

        // Coins (in x) must agree with No. of coins at the chosen x (blocks 6x with x=36, etc.).
        if (! empty($expected['check_equation']) && ($cellResults['x_value'] ?? false)) {
            $xGot = $submission['x_value'] ?? null;
            foreach ($rows as $row) {
                $rowKey = (string) ($row['key'] ?? '');
                if ($rowKey === '' || ! is_array($expected[$rowKey] ?? null)) {
                    continue;
                }
                if (! array_key_exists('count_x', $expected[$rowKey]) || ! array_key_exists('count', $expected[$rowKey])) {
                    continue;
                }
                $expr = data_get($submission, "rows.{$rowKey}.count_x");
                $count = data_get($submission, "rows.{$rowKey}.count");
                $eval = $this->evaluateExprAtX($expr, $xGot);
                $countNum = $this->normalizeNumber($count);
                if ($eval === null || $countNum === null || abs($eval - (float) $countNum) > 0.0001) {
                    $cellResults["{$rowKey}.count_x"] = false;
                    $cellResults['x_value'] = false;
                    $allOk = false;
                }
            }
        }

        $message = 'Table looks good — well done.';
        if (! $allOk) {
            if (($cellResults['equation'] ?? true) === false) {
                $message = 'Check your equation — add the Coins (in x) column and set it equal to total coins.';
            } elseif (($cellResults['x_value'] ?? true) === false) {
                $message = 'Equation may be right, but x is wrong. Solve again, then fill No. of coins.';
            } else {
                $denomWrong = collect($cellResults)
                    ->filter(fn ($ok, $key) => ! $ok && str_ends_with((string) $key, '.denomination'))
                    ->isNotEmpty();
                $message = $denomWrong
                    ? 'Check Denomination (in ₹) — wrong cells are highlighted. Use the number only (e.g. 2, 5, 10).'
                    : 'Some cells are wrong (highlighted). Fix them and submit again.';
            }
        }

        return [
            'ok' => $allOk,
            'cell_results' => $cellResults,
            'message' => $message,
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
    /**
     * Pull equation text from several possible request shapes (WAFs sometimes
     * strip a bare "equation" field that contains + or =).
     */
    private function extractSubmittedEquation(array $submission): ?string
    {
        foreach (['equation', 'coin_equation', 'eq'] as $key) {
            if (! array_key_exists($key, $submission) || $submission[$key] === null || $submission[$key] === '') {
                continue;
            }
            $text = (string) $submission[$key];
            // Frontend may encode + / = so proxies do not mangle them.
            $text = str_replace(['__P__', '__E__', '__D__'], ['+', '=', '/'], $text);

            return $text;
        }

        if (is_string($submission['grand_count'] ?? null) && str_contains((string) $submission['grand_count'], '=')) {
            return (string) $submission['grand_count'];
        }

        return null;
    }

    /**
     * True when every Coins (in x) cell and Total coins match the answer key.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $submission
     */
    private function moneyCountXTableProvesEquation(array $expected, array $submission): bool
    {
        $seen = false;
        foreach ($expected as $rowKey => $row) {
            if (! is_array($row) || ! array_key_exists('count_x', $row)) {
                continue;
            }
            $seen = true;
            if (! $this->valueMatches($row['count_x'], data_get($submission, "rows.{$rowKey}.count_x"))) {
                return false;
            }
        }

        if (! $seen || ! array_key_exists('grand_count', $expected)) {
            return false;
        }

        $grand = $submission['grand_count'] ?? null;
        if (is_string($grand) && str_contains($grand, '=')) {
            return false;
        }

        return $this->valueMatches($expected['grand_count'], $grand);
    }

    private function equationMatchesExpected(array $expected, array $submission): bool
    {
        $got = $submission['equation'] ?? null;
        // Allow equation typed into the Total coins cell by mistake.
        if (($got === null || trim((string) $got) === '')
            && is_string($submission['grand_count'] ?? null)
            && str_contains((string) $submission['grand_count'], '=')) {
            $got = $submission['grand_count'];
        }

        $gotCanon = $this->equationCanonical($got);
        if ($gotCanon === null) {
            return false;
        }

        // Authoritative money target from exercise answers: 2x+3x+4x=27
        $answerParts = [];
        foreach ($expected as $row) {
            if (! is_array($row) || ! array_key_exists('count_x', $row)) {
                continue;
            }
            $cx = $row['count_x'];
            $answerParts[] = is_array($cx) ? (string) ($cx[0] ?? '') : (string) $cx;
        }
        $answerParts = array_values(array_filter($answerParts, fn ($p) => $p !== ''));
        if ($answerParts !== [] && array_key_exists('grand_count', $expected)) {
            $target = implode('+', $answerParts).'='.$expected['grand_count'];
            $targetCanon = $this->equationCanonical($target);
            if ($this->equationCanonsMatch($targetCanon, $gotCanon)) {
                return true;
            }
        }

        // Marks: build target from the Total column + score.
        $correctTotal = data_get($submission, 'rows.correct.total');
        $wrongTotal = data_get($submission, 'rows.wrong.total');
        $score = $submission['grand_total'] ?? null;
        if ($correctTotal !== null && $wrongTotal !== null && $score !== null && $score !== '') {
            $fromTable = $this->equationCanonical(
                '('.$correctTotal.')+('.$wrongTotal.')='.$score
            );
            if ($this->equationCanonsMatch($fromTable, $gotCanon)) {
                return true;
            }

            // Same with explicit leading + on a negative wrong total: 2x+(-20+x)=25
            $fromTableParen = $this->equationCanonical(
                $correctTotal.'+('.$wrongTotal.')='.$score
            );
            if ($this->equationCanonsMatch($fromTableParen, $gotCanon)) {
                return true;
            }
        }

        // Money: sum submitted Coins (in x) = total coins (typed or from answers).
        $countXParts = [];
        foreach ($submission['rows'] ?? [] as $cells) {
            if (! is_array($cells)) {
                continue;
            }
            $part = trim((string) ($cells['count_x'] ?? ''));
            if ($part !== '') {
                $countXParts[] = $part;
            }
        }
        $totalCoins = $submission['grand_count'] ?? null;
        if (! is_string($totalCoins) || $totalCoins === '' || str_contains($totalCoins, '=')) {
            $totalCoins = $expected['grand_count'] ?? null;
        }
        if ($countXParts !== [] && $totalCoins !== null && $totalCoins !== '') {
            $fromCountX = $this->equationCanonical(implode('+', $countXParts).'='.$totalCoins);
            if ($this->equationCanonsMatch($fromCountX, $gotCanon)) {
                return true;
            }
        }

        // Listed acceptable strings in the exercise.
        $candidates = $expected['equation'] ?? [];
        if (! is_array($candidates)) {
            $candidates = [$candidates];
        }
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $wantCanon = $this->equationCanonical($candidate);
            if ($this->equationCanonsMatch($wantCanon, $gotCanon)) {
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
        // Allow "₹2", "Rs.5", "rs 10" for denomination cells.
        $text = preg_replace('/^(₹|rs\.?)/iu', '', $text) ?? $text;

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
        // Any remaining letter is treated as the variable x (covers lookalike glyphs).
        $text = preg_replace('/\p{L}/u', 'x', $text) ?? $text;
        $text = preg_replace('/\p{Pd}/u', '-', $text) ?? $text; // any dash/minus unicode
        $text = str_replace(['−', '–', '—', '﹣', '－', '­'], '-', $text);
        $text = str_replace(['＝', '﹦', '≟', '≡'], '=', $text);
        $text = str_replace(['＋', '﹢'], '+', $text);
        $text = preg_replace('/\s+/u', '', $text) ?? $text;
        // Collapse xxx from multi-letter tokens back to a single x.
        $text = preg_replace('/x+/', 'x', $text) ?? $text;

        // × / ⋅ after a digit or as a bare term is usually the variable x (2× → 2x).
        // Remaining × are true multiplication.
        $text = preg_replace('/(\d)[×⋅]/u', '$1x', $text) ?? $text;
        $text = preg_replace('/[×⋅](?=[+=\-)]|$)/u', 'x', $text) ?? $text;
        // Recover if a client already turned variable × into *: 2*+3*+4*=27 → 2x+3x+4x=27
        $text = preg_replace('/(\d)\*(?=[+=\-]|$)/', '$1x', $text) ?? $text;
        $text = preg_replace('/(\d)\*\+/', '$1x+', $text) ?? $text;

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

        // Fraction forms before dropping parentheses: (1/2)x → 1/2x
        $text = preg_replace('/\((\d+)\/(\d+)\)x/', '$1/$2x', $text) ?? $text;
        $text = str_replace(['(', ')'], '', $text);

        // School forms: 1/2x → x/2 ; 3/4x → 3x/4
        $text = preg_replace('/(?<![0-9x\/])1\/(\d+)x/', 'x/$1', $text) ?? $text;
        $text = preg_replace('/(?<![0-9x\/])(\d+)\/(\d+)x/', '$1x/$2', $text) ?? $text;

        $text = str_replace(['+-', '-+'], '-', $text);
        $text = str_replace(['--', '++'], ['+', '+'], $text);
        $text = str_replace(['+1x', '-1x'], ['+x', '-x'], $text);

        // Keep / for forms like x/2 and 3x/4.
        $text = preg_replace('/[^0-9x=+\-\/]/', '', $text) ?? $text;

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
     * True when two canonical forms are the same equation (including 3x+x+x/2=45 ≡ 9x=90).
     *
     * @param  array{0: int, 1: int}|null  $a
     * @param  array{0: int, 1: int}|null  $b
     */
    private function equationCanonsMatch(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        // Same root: ax+b=0 ⇒ x = -b/a
        if ($a[0] !== 0 && $b[0] !== 0) {
            return abs((-$a[1] / $a[0]) - (-$b[1] / $b[0])) < 0.0001;
        }

        return $a[0] === $b[0] && $a[1] === $b[1];
    }

    /**
     * Canonical ax+b=0 form for a linear equation in x (sign-normalized).
     * Supports fractions such as x/2+x/3+x/4=39.
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

        if (str_contains($text, '/')) {
            $leftCoeffs = $this->linearCoeffsRational($left ?? '');
            $rightCoeffs = $this->linearCoeffsRational($right ?? '');
            if ($leftCoeffs === null || $rightCoeffs === null) {
                return null;
            }
            $a = $leftCoeffs[0] - $rightCoeffs[0];
            $b = $leftCoeffs[1] - $rightCoeffs[1];

            return $this->canonicalIntsFromFloats($a, $b);
        }

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
     * @return array{0: int, 1: int}|null
     */
    private function canonicalIntsFromFloats(float $a, float $b): ?array
    {
        $scale = 1000000;
        $ai = (int) round($a * $scale);
        $bi = (int) round($b * $scale);
        if ($ai === 0 && $bi === 0) {
            return [0, 0];
        }
        $g = $this->gcd(abs($ai), abs($bi));
        if ($g > 1) {
            $ai = intdiv($ai, $g);
            $bi = intdiv($bi, $g);
        }
        if ($ai < 0 || ($ai === 0 && $bi < 0)) {
            $ai = -$ai;
            $bi = -$bi;
        }

        return [$ai, $bi];
    }

    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a === 0 ? 1 : abs($a);
    }

    /**
     * Parse ax+b with optional fractional x terms (x/2, 3x/4) into float [a, b].
     *
     * @return array{0: float, 1: float}|null
     */
    private function linearCoeffsRational(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return [0.0, 0.0];
        }

        if (preg_match('/[^0-9x+\-\/]/', $expr)) {
            return null;
        }

        if ($expr[0] !== '+' && $expr[0] !== '-') {
            $expr = '+'.$expr;
        }

        // +6x/4, +x/2, +6x, +12, -x
        if (! preg_match_all('/([+-])(?:(\d*)x(?:\/(\d+))?|(\d+))/', $expr, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $consumed = '';
        $a = 0.0;
        $b = 0.0;

        foreach ($matches as $match) {
            $sign = $match[1] === '-' ? -1.0 : 1.0;
            $consumed .= $match[0];

            if (($match[4] ?? '') !== '') {
                $b += $sign * (float) $match[4];

                continue;
            }

            $num = ($match[2] ?? '') === '' ? 1 : (int) $match[2];
            $den = ($match[3] ?? '') === '' ? 1 : (int) $match[3];
            if ($den === 0) {
                return null;
            }
            $a += $sign * ($num / $den);
        }

        if ($consumed !== $expr) {
            return null;
        }

        return [$a, $b];
    }

    /**
     * Evaluate a linear expression in x (supports 6x, x/2, (1/2)x).
     */
    private function evaluateExprAtX(mixed $expr, mixed $xValue): ?float
    {
        $xNum = $this->normalizeNumber($xValue);
        if ($xNum === null) {
            return null;
        }
        $text = $this->normalizeExpression($expr);
        if ($text === null || str_contains($text, '=')) {
            return null;
        }

        if (str_contains($text, '/')) {
            $coeffs = $this->linearCoeffsRational($text);
        } else {
            $intCoeffs = $this->linearCoeffs($text);
            $coeffs = $intCoeffs === null ? null : [(float) $intCoeffs[0], (float) $intCoeffs[1]];
        }

        if ($coeffs === null) {
            return null;
        }

        return $coeffs[0] * (float) $xNum + $coeffs[1];
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
