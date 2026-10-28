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
            'kind' => $card->kind,
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
                'kind' => $card->kind,
                'accent' => $card->accent ?: 'teal',
                'method_blurb' => $card->method_blurb,
                'table_schema' => $schema,
                'exercises_total' => $count,
                'series_label' => $this->seriesLabel($card),
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
     * The next method in this part's series: same sub heading, or the same
     * heading before the dash (Basic 1, Basic 2, …). Students only receive a
     * part that is ticked for their class.
     */
    public function nextPartCard(MmmCard $card, ?int $onlyGradeLevelId = null): ?MmmCard
    {
        $passedCurrent = false;
        foreach ($this->seriesCards($card) as $other) {
            if ((int) $other->id === (int) $card->id) {
                $passedCurrent = true;

                continue;
            }
            if (! $passedCurrent) {
                continue;
            }
            if ($onlyGradeLevelId !== null && ! $other->gradeLevels->contains('id', $onlyGradeLevelId)) {
                continue;
            }

            return $other;
        }

        return null;
    }

    /**
     * @return Collection<int, MmmCard>
     */
    private function seriesCards(MmmCard $card): Collection
    {
        $ids = [(int) $card->id];
        $sub = trim((string) $card->sub_heading);
        if ($sub !== '') {
            $ids = array_merge($ids, MmmCard::query()
                ->where('status', MmmCard::STATUS_PUBLISHED)
                ->where('sub_heading', $sub)
                ->pluck('id')
                ->all());
        }

        $key = $this->seriesKey((string) ($card->heading ?: $card->title));
        if ($key !== '') {
            $like = $key.' — %';
            $ids = array_merge($ids, MmmCard::query()
                ->where('status', MmmCard::STATUS_PUBLISHED)
                ->where(function ($query) use ($key, $like) {
                    $query->where('heading', $key)
                        ->orWhere('heading', 'like', $like)
                        ->orWhere('title', $key)
                        ->orWhere('title', 'like', $like);
                })
                ->pluck('id')
                ->all());
        }

        if ($this->isEquationSeries($card)) {
            $ids = array_merge($ids, MmmCard::query()
                ->where('status', MmmCard::STATUS_PUBLISHED)
                ->where(function ($query) {
                    $query->where('slug', 'balance-the-equation')
                        ->orWhere('slug', 'like', 'balance-equation-basic-%');
                })
                ->pluck('id')
                ->all());
        }

        return MmmCard::query()
            ->with('gradeLevels')
            ->whereIn('id', array_values(array_unique(array_map('intval', $ids))))
            ->where('status', MmmCard::STATUS_PUBLISHED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    private function seriesKey(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        $parts = preg_split('/\s+[—–]\s+|\s+-\s+/u', $label, 2);

        return trim((string) ($parts[0] ?? ''));
    }

    private function seriesLabel(MmmCard $card): string
    {
        $sub = trim((string) $card->sub_heading);
        if ($sub !== '') {
            return $sub;
        }
        if ($this->isEquationSeries($card)) {
            return 'Balance the equation';
        }

        $key = $this->seriesKey((string) ($card->heading ?: $card->title));

        return $key !== '' ? $key : (string) ($card->heading ?: $card->title);
    }

    private function isEquationSeries(MmmCard $card): bool
    {
        return $card->slug === 'balance-the-equation'
            || str_starts_with((string) $card->slug, 'balance-equation-basic-');
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
        $isBalance = ($answers['mode'] ?? '') === 'balance';
        $isFillPage = ($answers['mode'] ?? '') === 'fill_page';
        $isBodmas = ($answers['mode'] ?? '') === 'bodmas';
        $isCongruence = ($answers['mode'] ?? '') === 'congruence';

        return [
            'id' => $exercise->id,
            'difficulty' => $exercise->difficulty,
            'is_demo' => (bool) $exercise->is_demo,
            'question_text' => $exercise->question_text,
            'sort_order' => $exercise->sort_order,
            'play_kind' => $isFillPage ? 'fill_page' : ($isBalance ? 'balance' : ($isBodmas ? 'bodmas' : ($isCongruence ? 'congruence' : 'table'))),
            'balance' => $isBalance ? ($answers['play'] ?? []) : null,
            'bodmas' => $isBodmas ? ($answers['play'] ?? []) : null,
            'congruence' => $isCongruence ? ($answers['play'] ?? []) : null,
            'fill_page' => $isFillPage ? ($answers['play'] ?? []) : null,
            'uses_variable' => $usesVariable,
            'variable_tip' => $tip,
            // Marks: auto from Total column. Money ratio/conditions: student types the equation.
            'show_auto_equation' => ! $askEquation && ($usesVariable || array_key_exists('equation', $answers)),
            'ask_equation' => $askEquation,
            'equation_prompt' => $answers['equation_prompt'] ?? 'Write the equation',
            'equation_help' => $answers['equation_help'] ?? null,
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
        if (($expected['mode'] ?? '') === 'balance') {
            return $this->checkBalanceSubmission($expected, $submission);
        }
        if (($expected['mode'] ?? '') === 'fill_page') {
            return $this->checkFillPageSubmission($expected, $submission);
        }
        if (($expected['mode'] ?? '') === 'bodmas') {
            return $this->checkBodmasSubmission($expected, $submission);
        }
        if (($expected['mode'] ?? '') === 'congruence') {
            return $this->checkCongruenceSubmission($expected, $submission);
        }

        $schema = $this->schemaForExercise($card, $exercise);
        $rows = $schema['rows'] ?? [];
        $editableFields = $this->editableFieldKeys($schema);
        $matchByDenomination = in_array('denomination', $editableFields, true);
        $cellResults = [];
        $allOk = true;
        $claimedDenoms = [];

        foreach ($rows as $row) {
            $rowKey = (string) ($row['key'] ?? '');
            if ($rowKey === '') {
                continue;
            }
            $fixedValues = is_array($row['fixed_values'] ?? null) ? $row['fixed_values'] : [];
            $answerRow = $this->expectedAnswerRowForSubmission(
                $expected,
                $rowKey,
                $submission,
                $matchByDenomination,
            );

            // Each denomination may appear only once when students can reorder rows.
            if ($matchByDenomination) {
                $denomNorm = $this->normalizeNumber(data_get($submission, "rows.{$rowKey}.denomination"));
                if ($denomNorm !== null) {
                    if (isset($claimedDenoms[$denomNorm])) {
                        $cellResults["{$rowKey}.denomination"] = false;
                        $allOk = false;
                        $answerRow = null;
                    } else {
                        $claimedDenoms[$denomNorm] = $rowKey;
                    }
                }
            }

            foreach ($editableFields as $field) {
                if (array_key_exists($field, $fixedValues)) {
                    continue;
                }
                if ($answerRow === null || ! array_key_exists($field, $answerRow)) {
                    // Unknown / unmatched denomination — mark denomination (and other filled fields) wrong.
                    if ($matchByDenomination && array_key_exists($field, (array) data_get($submission, "rows.{$rowKey}", []))) {
                        $cellResults["{$rowKey}.{$field}"] = false;
                        $allOk = false;
                    }

                    continue;
                }
                $cellKey = "{$rowKey}.{$field}";
                if ($field === 'denomination' && array_key_exists($cellKey, $cellResults) && $cellResults[$cellKey] === false) {
                    continue;
                }
                $ok = $this->valueMatches(
                    $answerRow[$field] ?? null,
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

        // Expressions in x must agree with the numeric column at the chosen x.
        // count_x → No. of coins/notes; value_x → Total value (note value-ratio sums).
        if (! empty($expected['check_equation']) && ($cellResults['x_value'] ?? false)) {
            $xGot = $submission['x_value'] ?? null;
            foreach ($rows as $row) {
                $rowKey = (string) ($row['key'] ?? '');
                if ($rowKey === '') {
                    continue;
                }
                $answerRow = $this->expectedAnswerRowForSubmission(
                    $expected,
                    $rowKey,
                    $submission,
                    $matchByDenomination,
                );
                if ($answerRow === null) {
                    continue;
                }

                if (array_key_exists('value_x', $answerRow) && array_key_exists('total', $answerRow)) {
                    $exprField = 'value_x';
                    $numericField = 'total';
                } elseif (array_key_exists('count_x', $answerRow) && array_key_exists('count', $answerRow)) {
                    $exprField = 'count_x';
                    $numericField = 'count';
                } else {
                    continue;
                }

                $expr = data_get($submission, "rows.{$rowKey}.{$exprField}");
                $numeric = data_get($submission, "rows.{$rowKey}.{$numericField}");
                $eval = $this->evaluateExprAtX($expr, $xGot);
                $num = $this->normalizeNumber($numeric);
                if ($eval === null || $num === null || abs($eval - (float) $num) > 0.0001) {
                    $cellResults["{$rowKey}.{$exprField}"] = false;
                    $cellResults['x_value'] = false;
                    $allOk = false;
                }
            }
        }

        $usesValueX = collect($expected)->contains(
            fn ($row) => is_array($row) && array_key_exists('value_x', $row),
        );

        $message = 'Table looks good — well done.';
        if (! $allOk) {
            if (($cellResults['equation'] ?? true) === false) {
                $message = $usesValueX
                    ? 'Check your equation — add the Value (in x) column and set it equal to total money.'
                    : 'Check your equation — add the Coins (in x) column and set it equal to total coins.';
            } elseif (($cellResults['x_value'] ?? true) === false) {
                $message = $usesValueX
                    ? 'Equation may be right, but x is wrong. Solve again, then fill Total value and No. of coins.'
                    : 'Equation may be right, but x is wrong. Solve again, then fill No. of coins.';
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

    /**
     * One-page fill-in-the-blanks: check one x at a time; all_ok only when every sum is right.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $submission
     * @return array{ok: bool, cell_results: array<string, bool>, message: string, all_ok?: bool, step_key?: string}
     */
    private function checkFillPageSubmission(array $expected, array $submission): array
    {
        $check = is_array($expected['check'] ?? null) ? $expected['check'] : [];
        $items = is_array($expected['play']['items'] ?? null) ? $expected['play']['items'] : [];
        $stepKeys = [];
        foreach ($items as $item) {
            if (is_array($item) && ($item['key'] ?? '') !== '') {
                $stepKeys[] = (string) $item['key'];
            }
        }
        if ($stepKeys === []) {
            $stepKeys = array_map('strval', array_keys($check));
        }

        $stepKey = $submission['step_key'] ?? null;
        $values = is_array($submission['values'] ?? null) ? $submission['values'] : [];
        if ($stepKey !== null && $stepKey !== '' && ! array_key_exists($stepKey, $values)) {
            $values[$stepKey] = $submission['value'] ?? null;
        }

        $cellResults = [];
        $allOk = true;
        $keysToCheck = ($stepKey !== null && $stepKey !== '') ? [$stepKey] : $stepKeys;

        foreach ($keysToCheck as $key) {
            $key = (string) $key;
            if (! array_key_exists($key, $check)) {
                $cellResults[$key] = false;
                $allOk = false;

                continue;
            }
            $ok = $this->integerTokenMatches($check[$key], $values[$key] ?? null);
            $cellResults[$key] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        $finished = false;
        if ($allOk && $stepKey === null && $keysToCheck === $stepKeys && $stepKeys !== []) {
            $finished = true;
        } elseif (count($values) >= count($stepKeys) && $stepKeys !== []) {
            $finished = true;
            foreach ($stepKeys as $key) {
                if (! $this->integerTokenMatches($check[$key] ?? null, $values[$key] ?? null)) {
                    $finished = false;
                    break;
                }
            }
        }

        $message = 'Right.';
        if (! $allOk) {
            $custom = $expected['wrong_message'] ?? null;
            $message = is_string($custom) && $custom !== ''
                ? $custom
                : 'Not yet. Work it in your copy — BODMAS on the numbers first, then keep the x-term together.';
        } elseif ($finished) {
            $message = 'All of them are right. Well done.';
        }

        return [
            'ok' => $allOk,
            'all_ok' => $finished,
            'cell_results' => $cellResults,
            'message' => $message,
            'step_key' => $stepKey,
        ];
    }

    /**
     * Congruence: align vertices (circles) and lengths (rectangles), mark, table, case.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $submission
     * @return array{ok: bool, cell_results: array<string, bool>, message: string, all_ok?: bool, step_key?: string}
     */
    private function checkCongruenceSubmission(array $expected, array $submission): array
    {
        $check = is_array($expected['check'] ?? null) ? $expected['check'] : [];
        $steps = is_array($expected['play']['steps'] ?? null) ? $expected['play']['steps'] : [];
        $stepKey = (string) ($submission['step_key'] ?? '');
        $values = is_array($submission['values'] ?? null) ? $submission['values'] : [];
        $keys = [];
        foreach ($steps as $step) {
            if (is_array($step) && ($step['key'] ?? '') !== '') {
                $keys[] = (string) $step['key'];
            }
        }
        $isLast = $keys !== [] && $stepKey === $keys[array_key_last($keys)];

        if (in_array($stepKey, ['order', 'align'], true)) {
            $orderCheck = is_array($check['order'] ?? null) ? $check['order'] : (is_array($check['align'] ?? null) ? $check['align'] : []);
            $want = array_map(fn ($letter) => strtoupper(trim((string) $letter)), $orderCheck['letters'] ?? []);
            $got = array_map(fn ($letter) => strtoupper(trim((string) $letter)), is_array($values['letters'] ?? null) ? $values['letters'] : []);
            $ok = $want !== [] && $got === $want;
            $cells = ['letters' => $ok];

            $wantAngle = strtoupper(trim((string) ($orderCheck['angle'] ?? '')));
            if ($wantAngle !== '') {
                $gotAngle = strtoupper(trim((string) ($values['angle'] ?? '')));
                $angleOk = $gotAngle === $wantAngle;
                $cells['angle'] = $angleOk;
                if (! $angleOk) {
                    $ok = false;
                }
            }

            $wantSides = is_array($orderCheck['sides'] ?? null) ? $orderCheck['sides'] : [];
            if ($wantSides !== []) {
                $gotSides = is_array($values['sides'] ?? null) ? $values['sides'] : [];
                foreach ($wantSides as $side => $mark) {
                    $match = $this->congruenceMeasure($gotSides[$side] ?? '', $mark);
                    $cells[(string) $side] = $match;
                    if (! $match) {
                        $ok = false;
                    }
                }
                foreach ($gotSides as $side => $mark) {
                    if ($mark === '' || $mark === null) {
                        continue;
                    }
                    if (! array_key_exists($side, $wantSides)) {
                        $cells[(string) $side] = false;
                        $ok = false;
                    }
                }
            }

            return $this->congruenceResult(
                $ok,
                $ok && $isLast,
                $stepKey,
                $ok
                    ? (string) ($orderCheck['ok'] ?? 'The second triangle is labelled in matching order.')
                    : (string) ($orderCheck['miss'] ?? 'Type the matching letter at each corner of the second triangle.'),
                $cells,
            );
        }

        if ($stepKey === 'marks') {
            $want = is_array($check['marks']['placed'] ?? null) ? $check['marks']['placed'] : [];
            $got = is_array($values['placed'] ?? null) ? $values['placed'] : [];
            $cells = [];
            $ok = $want !== [];
            foreach ($want as $part => $mark) {
                $match = (string) ($got[$part] ?? '') === (string) $mark;
                $cells[(string) $part] = $match;
                if (! $match) {
                    $ok = false;
                }
            }
            foreach ($got as $part => $mark) {
                if (! array_key_exists($part, $want)) {
                    $cells[(string) $part] = false;
                    $ok = false;
                }
            }

            return $this->congruenceResult(
                $ok,
                $ok && $isLast,
                $stepKey,
                $ok ? (string) ($check['marks']['ok'] ?? 'The marks match.') : (string) ($check['marks']['miss'] ?? 'A mark is on the wrong part.'),
                $cells,
            );
        }

        if ($stepKey === 'table') {
            $wantRows = is_array($check['table']['rows'] ?? null) ? array_values($check['table']['rows']) : [];
            $gotRows = is_array($values['rows'] ?? null) ? array_values($values['rows']) : [];
            $cells = [];
            $ok = count($wantRows) > 0 && count($gotRows) === count($wantRows);
            foreach ($wantRows as $index => $row) {
                $got = is_array($gotRows[$index] ?? null) ? $gotRows[$index] : [];
                $needType = trim((string) ($row['type'] ?? '')) !== '';
                $match = $this->congruenceSame($got['parts'] ?? '', $row['parts'] ?? '')
                    && $this->congruenceSame($got['reason'] ?? '', $row['reason'] ?? '')
                    && (! $needType || $this->congruenceSame($got['type'] ?? '', $row['type'] ?? ''));
                $cells['row'.$index] = $match;
                if (! $match) {
                    $ok = false;
                }
            }

            $wantCase = strtoupper(trim((string) ($check['case']['value'] ?? '')));
            $caseMessage = null;
            if ($wantCase !== '') {
                $gotCase = strtoupper(trim((string) ($values['case'] ?? '')));
                $caseOk = $gotCase === $wantCase;
                $cells['case'] = $caseOk;
                if (! $caseOk) {
                    $ok = false;
                    $wrong = is_array($check['case']['wrong'] ?? null) ? $check['case']['wrong'] : [];
                    $caseMessage = (string) ($wrong[$gotCase] ?? $check['case']['miss'] ?? 'Read the table again and name the case.');
                }
            }

            $summary = ($ok && $isLast && is_array($check['summary'] ?? null)) ? $check['summary'] : null;
            $message = $ok
                ? (string) ($check['table']['ok'] ?? $check['case']['ok'] ?? 'The table is right.')
                : (string) ($caseMessage ?? $check['table']['miss'] ?? 'Check the equal parts and the reasons.');

            return $this->congruenceResult($ok, $ok && $isLast, $stepKey, $message, $cells, $summary);
        }

        if ($stepKey === 'case') {
            $got = strtoupper(trim((string) ($values['case'] ?? $submission['value'] ?? '')));
            $want = strtoupper(trim((string) ($check['case']['value'] ?? '')));
            $ok = $want !== '' && $got === $want;
            $wrong = is_array($check['case']['wrong'] ?? null) ? $check['case']['wrong'] : [];
            $message = $ok
                ? (string) ($check['case']['ok'] ?? 'That is the case.')
                : (string) ($wrong[$got] ?? $check['case']['miss'] ?? 'Read the table again and name the case.');

            $summary = ($ok && $isLast && is_array($check['summary'] ?? null)) ? $check['summary'] : null;

            return $this->congruenceResult($ok, $ok && $isLast, $stepKey, $message, [$stepKey => $ok], $summary);
        }

        return $this->congruenceResult(false, false, $stepKey, 'That step is not on this figure.');
    }

    /**
     * @param  array<string, bool>  $cells
     * @return array{ok: bool, cell_results: array<string, bool>, message: string, all_ok: bool, step_key: string}
     */
    private function congruenceResult(bool $ok, bool $finished, string $stepKey, string $message, array $cells = [], ?array $summary = null): array
    {
        $result = [
            'ok' => $ok,
            'all_ok' => $finished,
            'cell_results' => $cells,
            'message' => $message,
            'step_key' => $stepKey,
        ];
        if ($summary !== null) {
            $result['summary'] = $summary;
        }

        return $result;
    }

    private function congruenceSame(mixed $got, mixed $want): bool
    {
        $clean = static function (mixed $value): string {
            $text = strtolower(trim((string) $value));
            $text = str_replace(['∠', ' '], ['angle', ''], $text);

            return $text;
        };

        return $clean($got) !== '' && $clean($got) === $clean($want);
    }

    private function congruenceMeasure(mixed $got, mixed $want): bool
    {
        $gotNumber = preg_replace('/[^0-9.]/', '', (string) $got) ?? '';
        $wantNumber = preg_replace('/[^0-9.]/', '', (string) $want) ?? '';
        if ($gotNumber !== '' && $wantNumber !== '' && $gotNumber === $wantNumber) {
            return true;
        }

        return $this->congruenceSame($got, $want);
    }

    /**
     * BODMAS: tap the part to do first, then fill the simplified line.
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $submission
     * @return array{ok: bool, cell_results: array<string, bool>, message: string, all_ok?: bool, step_key?: string}
     */
    private function checkBodmasSubmission(array $expected, array $submission): array
    {
        $check = is_array($expected['check'] ?? null) ? $expected['check'] : [];
        $playSteps = is_array($expected['play']['steps'] ?? null) ? $expected['play']['steps'] : [];
        $stepKey = (string) ($submission['step_key'] ?? '');
        $values = is_array($submission['values'] ?? null) ? $submission['values'] : [];
        $playStep = $this->findBodmasStep($playSteps, $stepKey);
        if ($playStep === null) {
            return [
                'ok' => false,
                'all_ok' => false,
                'cell_results' => [],
                'message' => 'That step is not on this sum.',
                'step_key' => $stepKey,
            ];
        }

        $stepType = (string) ($playStep['type'] ?? '');
        if ($stepType === 'tap') {
            $need = array_values(array_map('strval', is_array($playStep['hits'] ?? null) ? $playStep['hits'] : []));
            $got = $this->bodmasHits($values, $submission);
            $cells = [];
            foreach ($need as $id) {
                $cells[$id] = in_array($id, $got, true);
            }
            foreach ($got as $id) {
                if (! in_array($id, $need, true)) {
                    $cells[$id] = false;
                }
            }
            $ok = $need !== [] && $cells !== [] && ! in_array(false, $cells, true) && count($got) === count($need);

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => $cells,
                'message' => $ok
                    ? (string) ($playStep['ok_message'] ?? 'That is the part to do first.')
                    : (string) ($playStep['miss'] ?? 'Tap the part BODMAS does first.'),
                'step_key' => $stepKey,
            ];
        }

        if ($stepType === 'next') {
            $want = strtolower(trim((string) ($playStep['value'] ?? 'ok')));
            $got = strtolower(trim((string) ($values[$stepKey] ?? $submission['value'] ?? '')));
            $ok = $got !== '' && $got === $want;

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => [$stepKey => $ok],
                'message' => $ok
                    ? (string) ($playStep['ok_message'] ?? 'Continue.')
                    : (string) ($playStep['miss'] ?? 'Continue when the line is open.'),
                'step_key' => $stepKey,
            ];
        }

        $cells = [];
        foreach ($playStep['pieces'] ?? [] as $piece) {
            if (! is_array($piece) || empty($piece['box'])) {
                continue;
            }
            $box = (string) $piece['box'];
            $cells[$box] = $this->integerTokenMatches($check[$box] ?? '', $values[$box] ?? null);
        }
        $ok = $cells !== [] && ! in_array(false, $cells, true);
        $finished = $ok && ! empty($playStep['_is_last']);

        return [
            'ok' => $ok,
            'all_ok' => $finished,
            'cell_results' => $cells,
            'message' => $ok
                ? (string) ($playStep['ok_message'] ?? ($finished ? 'That is the value.' : 'That line is right.'))
                : (string) ($playStep['miss'] ?? 'Check the number you wrote.'),
            'step_key' => $stepKey,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>|null
     */
    private function findBodmasStep(array $steps, string $key): ?array
    {
        if ($key === '') {
            return null;
        }
        foreach ($steps as $index => $step) {
            if (! is_array($step) || (string) ($step['key'] ?? '') !== $key) {
                continue;
            }
            $copy = $step;
            $copy['_is_last'] = $index === array_key_last($steps);

            return $copy;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $submission
     * @return list<string>
     */
    private function bodmasHits(array $values, array $submission): array
    {
        $raw = $values['hits'] ?? ($submission['value'] ?? []);
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : explode(',', $raw);
        }
        if (! is_array($raw)) {
            return [];
        }
        $hits = [];
        foreach ($raw as $id) {
            $id = trim((string) $id);
            if ($id !== '' && ! in_array($id, $hits, true)) {
                $hits[] = $id;
            }
        }

        return $hits;
    }

    /**
     * Stepwise check for Balance the equation (integers, terms, and divide).
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $submission
     * @return array{ok: bool, cell_results: array<string, bool>, message: string, all_ok?: bool, step_key?: string}
     */
    private function checkBalanceSubmission(array $expected, array $submission): array
    {
        $check = is_array($expected['check'] ?? null) ? $expected['check'] : [];
        $playSteps = is_array($expected['play']['steps'] ?? null) ? $expected['play']['steps'] : [];
        $stepKeys = [];
        foreach ($playSteps as $step) {
            if (is_array($step) && ($step['key'] ?? '') !== '') {
                $stepKeys[] = (string) $step['key'];
            }
        }
        if ($stepKeys === []) {
            $stepKeys = array_map('strval', array_keys($check));
        }

        $stepKey = $submission['step_key'] ?? null;
        $values = is_array($submission['values'] ?? null) ? $submission['values'] : [];
        if ($stepKey !== null && $stepKey !== '' && ! array_key_exists($stepKey, $values)) {
            $values[$stepKey] = $submission['value'] ?? null;
        }

        $playStep = is_string($stepKey) && $stepKey !== ''
            ? $this->findBalanceStep($playSteps, $stepKey)
            : null;
        $stepType = is_array($playStep) ? (string) ($playStep['type'] ?? '') : '';
        if ($stepType === 'pick_side') {
            $allowed = [];
            foreach ($playStep['choices'] ?? [] as $choice) {
                if (is_array($choice) && ($choice['value'] ?? '') !== '') {
                    $allowed[] = strtolower((string) $choice['value']);
                }
            }
            $got = strtolower(trim((string) ($values[$stepKey] ?? '')));
            $ok = $got !== '' && in_array($got, $allowed, true);

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => [(string) $stepKey => $ok],
                'message' => $ok
                    ? 'Either side is fine. Continue.'
                    : $this->balanceStepHint($stepKey, $playSteps),
                'step_key' => $stepKey,
            ];
        }
        if (in_array($stepType, ['copy_line', 'frac_board', 'stacked_line'], true)) {
            $boxes = $this->collectBalanceBoxes(is_array($playStep) ? $playStep : []);
            $ok = true;
            $cells = [];
            foreach ($boxes as $box) {
                $cellOk = $this->integerTokenMatches($check[$box] ?? '', $values[$box] ?? null);
                $cells[$box] = $cellOk;
                if (! $cellOk) {
                    $ok = false;
                }
            }
            foreach ($playStep['commute'] ?? [] as $pair) {
                if (! is_array($pair) || count($pair) !== 2) {
                    continue;
                }
                $a = (string) $pair[0];
                $b = (string) $pair[1];
                $direct = $this->integerTokenMatches($check[$a] ?? '', $values[$a] ?? null)
                    && $this->integerTokenMatches($check[$b] ?? '', $values[$b] ?? null);
                $swapped = $this->integerTokenMatches($check[$a] ?? '', $values[$b] ?? null)
                    && $this->integerTokenMatches($check[$b] ?? '', $values[$a] ?? null);
                if ($direct || $swapped) {
                    $cells[$a] = true;
                    $cells[$b] = true;
                }
            }
            $ok = $cells !== [] && ! in_array(false, $cells, true);
            if ($cells === []) {
                $ok = false;
            }
            $finished = $ok && ! empty($playStep['_is_last']);

            return [
                'ok' => $ok,
                'all_ok' => $finished,
                'cell_results' => $cells,
                'message' => $ok
                    ? ($finished ? 'x is correct — well done. Both sides stay equal.' : 'That line is right. Write the next line.')
                    : $this->balanceStepHint($stepKey, $playSteps),
                'step_key' => $stepKey,
            ];
        }
        if ($stepType === 'note_next') {
            $want = strtolower(trim((string) ($playStep['value'] ?? 'ok')));
            $got = strtolower(trim((string) ($values[$stepKey] ?? '')));
            $ok = $got !== '' && $got === $want;

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => [(string) $stepKey => $ok],
                'message' => $ok
                    ? (string) ($playStep['ok_message'] ?? 'Brackets are on. Continue.')
                    : (string) ($playStep['miss'] ?? 'Put brackets around each numerator that has more than one term.'),
                'step_key' => $stepKey,
            ];
        }
        if ($stepType === 'common_den') {
            $mulKey = (string) ($playStep['mul_key'] ?? 'mul');
            $topKey = (string) ($playStep['top_key'] ?? 'top');
            $mulOk = $this->integerTokenMatches($check[$mulKey] ?? '', $values[$mulKey] ?? null);
            $topOk = $this->integerTokenMatches($check[$topKey] ?? '', $values[$topKey] ?? null);
            $ok = $mulOk && $topOk;

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => ['common_den' => $ok],
                'message' => $ok
                    ? 'Same denominator. Continue.'
                    : $this->balanceStepHint($stepKey, $playSteps),
                'step_key' => $stepKey,
            ];
        }
        if ($stepType === 'frac_x') {
            $topKey = (string) ($playStep['top_key'] ?? 'top');
            $resultKey = (string) ($playStep['result_key'] ?? 'result');
            $topOk = $this->integerTokenMatches($check[$topKey] ?? '', $values[$topKey] ?? null);
            $resultOk = $this->integerTokenMatches($check[$resultKey] ?? '', $values[$resultKey] ?? null);
            $ok = $topOk && $resultOk;

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => ['frac_x' => $ok],
                'message' => $ok
                    ? 'That fraction is right. Put x on it and continue.'
                    : $this->balanceStepHint($stepKey, $playSteps),
                'step_key' => $stepKey,
            ];
        }
        if ($stepType === 'write_frac') {
            $numOk = $this->factorPairMatches($check['frac_num'] ?? [], $values, $playStep['num_keys'] ?? ['n1', 'n2']);
            $denOk = $this->factorPairMatches($check['frac_den'] ?? [], $values, $playStep['den_keys'] ?? ['d1', 'd2']);
            $ok = $numOk && $denOk;

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => ['frac' => $ok],
                'message' => $ok
                    ? 'That step is right. Continue.'
                    : $this->balanceStepHint($stepKey, $playSteps),
                'step_key' => $stepKey,
            ];
        }
        if ($stepType === 'keep_flip') {
            $numOk = $this->integerTokenMatches($check['flip_n'] ?? '', $values['flip_n'] ?? null);
            $denOk = $this->integerTokenMatches($check['flip_d'] ?? '', $values['flip_d'] ?? null);
            $ok = $numOk && $denOk;

            return [
                'ok' => $ok,
                'all_ok' => false,
                'cell_results' => ['keep_flip' => $ok],
                'message' => $ok
                    ? 'That step is right. Continue.'
                    : $this->balanceStepHint($stepKey, $playSteps),
                'step_key' => $stepKey,
            ];
        }

        $cellResults = [];
        $allOk = true;
        $keysToCheck = ($stepKey !== null && $stepKey !== '') ? [$stepKey] : $stepKeys;
        $pairKey = is_array($playStep) ? (string) ($playStep['pair_key'] ?? '') : '';
        if ($pairKey !== '' && ! in_array($pairKey, $keysToCheck, true)) {
            $keysToCheck[] = $pairKey;
        }

        foreach ($keysToCheck as $key) {
            $key = (string) $key;
            if (! array_key_exists($key, $check)) {
                $cellResults[$key] = false;
                $allOk = false;

                continue;
            }
            $ok = $this->integerTokenMatches($check[$key], $values[$key] ?? null);
            $cellResults[$key] = $ok;
            if (! $ok) {
                $allOk = false;
            }
        }

        $finished = $allOk && is_string($stepKey) && $stepKey !== '' && is_array($playStep) && ! empty($playStep['_is_last']);
        if (($stepKey === null || $stepKey === '') && $allOk && $keysToCheck === $stepKeys) {
            $finished = true;
        }

        $message = 'That step is right. Continue.';
        if (! $allOk) {
            $message = $this->balanceStepHint($stepKey, $playSteps);
        } elseif ($finished) {
            $message = 'x is correct — well done. Both sides stay equal.';
        }

        return [
            'ok' => $allOk,
            'all_ok' => $finished,
            'cell_results' => $cellResults,
            'message' => $message,
            'step_key' => $stepKey,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>|null
     */
    private function findBalanceStep(array $steps, string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }
            if ((string) ($step['key'] ?? '') === $key) {
                $copy = $step;
                $branches = $step['branches'] ?? null;
                $copy['_is_last'] = $index === array_key_last($steps) && empty($branches);

                return $copy;
            }
            $branches = is_array($step['branches'] ?? null) ? $step['branches'] : [];
            foreach ($branches as $branchSteps) {
                if (! is_array($branchSteps)) {
                    continue;
                }
                $found = $this->findBalanceStep(array_values($branchSteps), $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return list<string>
     */
    private function collectBalanceBoxes(array $step): array
    {
        $boxes = [];
        foreach ($step['parts'] ?? [] as $part) {
            if (is_array($part) && ! empty($part['box'])) {
                $boxes[] = (string) $part['box'];
            }
        }
        foreach ($step['columns'] ?? [] as $column) {
            if (! is_array($column)) {
                continue;
            }
            foreach ($column['terms'] ?? [] as $term) {
                if (is_array($term) && ! empty($term['box'])) {
                    $boxes[] = (string) $term['box'];
                }
                if (is_array($term) && ! empty($term['den_mul'])) {
                    $boxes[] = (string) $term['den_mul'];
                }
            }
            if (! empty($column['den_box'])) {
                $boxes[] = (string) $column['den_box'];
            }
        }
        foreach (['left', 'right'] as $side) {
            $pieces = $step[$side]['pieces'] ?? [];
            if (! is_array($pieces)) {
                continue;
            }
            foreach ($pieces as $piece) {
                if (is_array($piece) && ! empty($piece['box'])) {
                    $boxes[] = (string) $piece['box'];
                }
            }
        }

        return $boxes;
    }

    /**
     * @param  list<array<string, mixed>>  $playSteps
     */
    private function balanceStepHint(?string $stepKey, array $playSteps): string
    {
        $step = $this->findBalanceStep($playSteps, (string) $stepKey);
        if ($step === null) {
            return 'Check that number and try again.';
        }
        if (! empty($step['miss'])) {
            return (string) $step['miss'];
        }
        $type = (string) ($step['type'] ?? '');

        return match ($type) {
            'balance' => 'Do the same on both sides. Add the opposite of the number stuck to x (include the minus if you need it).',
            'simplify_right' => 'First work out the numbers on the right of = .',
            'simplify_left' => 'First work out the numbers on the left of = .',
            'move' => 'Add the opposite of the number stuck to x.',
            'impact' => 'The x side is now x + 0. Only fill the number box on the other side.',
            'divide' => 'Divide both sides by the same number (the coefficient of x).',
            'isolate_term' => '2x is one term. Move the extra number so you have 2x = something.',
            'term_value' => 'Work out that number. Keep 2x together.',
            'open_bracket' => 'Open the bracket first.',
            'rhs_value' => 'Now work out the right-hand side.',
            'take_number' => 'Take the loose number across. Keep the x-term together.',
            'multiply' => 'Multiply both sides by the same number (the denominator) so it cancels.',
            'cross' => 'Cross-multiply: each numerator times the other side’s denominator.',
            'write_frac' => 'Write x as a fraction: other-side top × this-side bottom, over (number in front of x) × other-side bottom. Do not multiply the products yet.',
            'keep_flip' => 'Keep the other side as it is. The fraction in front of x flips when it moves across.',
            'cut' => 'Cancel a top number with a bottom number. Write what each pair becomes.',
            'solve' => 'x is alone now. Work out the number.',
            'pick_side' => 'Left or right — both are fine. Choose one side.',
            'x_term' => 'Join the x-terms and write the single x-term, such as −x or 4x.',
            'blank' => 'Work out that number. Mind the sign.',
            'frac_x' => 'Solve the fraction the usual way, then put x on the answer. Write the whole number over the denominator first.',
            'common_den' => '3x is 3x/1. Multiply the top and the bottom by 3. Then 3 × 3 is the new top, with x.',
            'copy_line' => 'Fill every box on this line. Simplify the left and the right together.',
            'frac_board' => 'One denominator on the left, one on the right. Write the number you multiply each fraction by, then the new denominator.',
            'stacked_line' => 'Fill the numerator. The denominator stays.',
            'note_next' => 'Put brackets around each numerator that has more than one term.',
            default => 'Check that number and try again.',
        };
    }

    /**
     * @param  list<mixed>  $expected
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function factorPairMatches(array $expected, array $values, array $keys): bool
    {
        $got = [];
        foreach ($keys as $key) {
            $got[] = $values[$key] ?? null;
        }
        if (count($got) !== count($expected)) {
            return false;
        }
        $used = array_fill(0, count($expected), false);
        foreach ($got as $value) {
            $found = false;
            foreach ($expected as $i => $want) {
                if ($used[$i]) {
                    continue;
                }
                if ($this->integerTokenMatches($want, $value)) {
                    $used[$i] = true;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    private function integerTokenMatches(mixed $expected, mixed $got): bool
    {
        if (is_array($expected)) {
            return $this->valueMatches($expected, $got);
        }

        if ($this->valueMatches($expected, $got)) {
            return true;
        }

        $want = $this->normalizeNumber($expected);
        $gotNum = $this->normalizeNumber($got);
        if ($want !== null && $gotNum !== null && $want === $gotNum) {
            return true;
        }

        $gotText = strtolower(trim((string) $got));
        $gotText = str_replace(['−', '–', '—', '(', ')', ' '], ['-', '-', '-', '', '', ''], $gotText);
        if ($gotText !== '' && $gotText[0] === '+') {
            $gotText = substr($gotText, 1);
        }
        $wantText = strtolower(trim((string) $expected));
        $wantText = str_replace(['−', '–', '—', '(', ')', ' '], ['-', '-', '-', '', '', ''], $wantText);
        if ($wantText !== '' && $wantText[0] === '+') {
            $wantText = substr($wantText, 1);
        }

        $wantF = $this->tokenToFloat($expected);
        $gotF = $this->tokenToFloat($got);
        if ($wantF !== null && $gotF !== null && abs($wantF - $gotF) < 0.0001) {
            return true;
        }

        return $wantText !== '' && $gotText === $wantText;
    }

    private function tokenToFloat(mixed $value): ?float
    {
        $text = trim(str_replace(['−', '–', '—', ' ', '(', ')'], ['-', '-', '-', '', '', ''], (string) $value));
        if ($text === '' || $text[0] === '+') {
            $text = ltrim($text, '+');
        }
        if (preg_match('/^(-?\d+)\/(-?\d+)$/', $text, $m)) {
            $den = (int) $m[2];
            if ($den === 0) {
                return null;
            }

            return ((int) $m[1]) / $den;
        }
        if ($text === '' || ! is_numeric($text)) {
            return null;
        }

        return (float) $text;
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
            $wantF = $this->tokenToFloat($candidate);
            $gotF = $this->tokenToFloat($got);
            if ($wantF !== null && $gotF !== null && abs($wantF - $gotF) < 0.0001) {
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
     * Resolve the answer-key row for a submitted table row.
     * When denomination is student-filled, match by denomination value (any row order).
     *
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $submission
     * @return array<string, mixed>|null
     */
    private function expectedAnswerRowForSubmission(
        array $expected,
        string $submittedRowKey,
        array $submission,
        bool $matchByDenomination,
    ): ?array {
        if ($matchByDenomination) {
            $denom = data_get($submission, "rows.{$submittedRowKey}.denomination");
            $matchKey = $this->expectedRowKeyForDenomination($expected, $denom);
            if ($matchKey === null) {
                return null;
            }

            return is_array($expected[$matchKey] ?? null) ? $expected[$matchKey] : null;
        }

        return is_array($expected[$submittedRowKey] ?? null) ? $expected[$submittedRowKey] : null;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function expectedRowKeyForDenomination(array $expected, mixed $denomination): ?string
    {
        $want = $this->normalizeNumber($denomination);
        if ($want === null) {
            return null;
        }

        foreach ($expected as $rowKey => $row) {
            if (! is_array($row) || ! array_key_exists('denomination', $row)) {
                continue;
            }
            $candidates = is_array($row['denomination']) ? $row['denomination'] : [$row['denomination']];
            foreach ($candidates as $candidate) {
                if ($this->normalizeNumber($candidate) === $want) {
                    return (string) $rowKey;
                }
            }
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
        $exprKey = null;
        foreach ($expected as $rowKey => $row) {
            if (! is_array($row)) {
                continue;
            }
            $exprKey = array_key_exists('value_x', $row) ? 'value_x' : (array_key_exists('count_x', $row) ? 'count_x' : null);
            if ($exprKey === null) {
                continue;
            }
            $seen = true;
            $submittedCells = null;
            if (array_key_exists('denomination', $row)) {
                $wantDenom = $this->normalizeNumber(
                    is_array($row['denomination']) ? ($row['denomination'][0] ?? null) : $row['denomination']
                );
                foreach ($submission['rows'] ?? [] as $cells) {
                    if (! is_array($cells)) {
                        continue;
                    }
                    if ($this->normalizeNumber($cells['denomination'] ?? null) === $wantDenom) {
                        $submittedCells = $cells;
                        break;
                    }
                }
            } else {
                $submittedCells = data_get($submission, "rows.{$rowKey}");
            }

            if (! is_array($submittedCells)
                || ! $this->valueMatches($row[$exprKey], $submittedCells[$exprKey] ?? null)) {
                return false;
            }
        }

        if (! $seen) {
            return false;
        }

        $grandKey = $exprKey === 'value_x' ? 'grand_total' : 'grand_count';
        if (! array_key_exists($grandKey, $expected)) {
            return false;
        }

        $grand = $submission[$grandKey] ?? null;
        if (is_string($grand) && str_contains($grand, '=')) {
            return false;
        }

        return $this->valueMatches($expected[$grandKey], $grand);
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

        // Authoritative money target from exercise answers: 2x+3x+4x=27 or 4x+5x+6x=7500
        $answerParts = [];
        $answerRhs = null;
        foreach ($expected as $row) {
            if (! is_array($row)) {
                continue;
            }
            $exprKey = array_key_exists('value_x', $row) ? 'value_x' : (array_key_exists('count_x', $row) ? 'count_x' : null);
            if ($exprKey === null) {
                continue;
            }
            $cx = $row[$exprKey];
            $answerParts[] = is_array($cx) ? (string) ($cx[0] ?? '') : (string) $cx;
            $answerRhs = $exprKey === 'value_x'
                ? ($expected['grand_total'] ?? null)
                : ($expected['grand_count'] ?? null);
        }
        $answerParts = array_values(array_filter($answerParts, fn ($p) => $p !== ''));
        if ($answerParts !== [] && $answerRhs !== null && $answerRhs !== '') {
            $target = implode('+', $answerParts).'='.$answerRhs;
            $targetCanon = $this->equationCanonical($target);
            if ($this->equationCanonsMatch($targetCanon, $gotCanon)) {
                return true;
            }
        }

        // 50*(2x)+100*(3x)+500*(5x)=5800 when notes-in-x are a ratio.
        $moneyParts = [];
        foreach ($expected as $row) {
            if (! is_array($row) || ! array_key_exists('count_x', $row) || ! array_key_exists('denomination', $row)) {
                continue;
            }
            $cx = $row['count_x'];
            $cx = is_array($cx) ? (string) ($cx[0] ?? '') : (string) $cx;
            $denom = $row['denomination'];
            $denom = is_array($denom) ? (string) ($denom[0] ?? '') : (string) $denom;
            if ($cx !== '' && $denom !== '') {
                $moneyParts[] = $denom.'*('.$cx.')';
            }
        }
        if ($moneyParts !== [] && ($expected['grand_total'] ?? '') !== '' && $expected['grand_total'] !== null) {
            $moneyTarget = implode('+', $moneyParts).'='.$expected['grand_total'];
            if ($this->equationCanonsMatch($this->equationCanonical($moneyTarget), $gotCanon)) {
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

        // Money: sum submitted Coins/Value (in x) = total coins or total money.
        $exprParts = [];
        foreach ($submission['rows'] ?? [] as $cells) {
            if (! is_array($cells)) {
                continue;
            }
            $part = trim((string) ($cells['count_x'] ?? $cells['value_x'] ?? ''));
            if ($part !== '') {
                $exprParts[] = $part;
            }
        }
        $rhs = $submission['grand_total'] ?? $submission['grand_count'] ?? null;
        if (! is_string($rhs) || $rhs === '' || str_contains($rhs, '=')) {
            $rhs = $expected['grand_total'] ?? $expected['grand_count'] ?? null;
        }
        // Prefer total money as RHS when value_x is used.
        $usesValueX = false;
        foreach ($submission['rows'] ?? [] as $cells) {
            if (is_array($cells) && trim((string) ($cells['value_x'] ?? '')) !== '') {
                $usesValueX = true;
                break;
            }
        }
        if ($usesValueX) {
            $rhs = $submission['grand_total'] ?? $expected['grand_total'] ?? $rhs;
        } elseif (! is_string($submission['grand_count'] ?? null) || ($submission['grand_count'] ?? '') === '' || str_contains((string) $submission['grand_count'], '=')) {
            $rhs = $expected['grand_count'] ?? $rhs;
        } else {
            $rhs = $submission['grand_count'];
        }
        if ($exprParts !== [] && $rhs !== null && $rhs !== '') {
            $fromParts = $this->equationCanonical(implode('+', $exprParts).'='.$rhs);
            if ($this->equationCanonsMatch($fromParts, $gotCanon)) {
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

        // Digit × digit is multiply (50×2x). Trailing × is the variable x (2× → 2x).
        $text = preg_replace('/(\d)[×⋅](\d)/u', '$1*$2', $text) ?? $text;
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

        // 50*(2x) → 100x ; 50*2x → 100x ; 2x*50 → 100x
        $text = preg_replace_callback(
            '/(\d+)\*\((\d+)x\)/',
            static fn (array $m): string => ((int) $m[1] * (int) $m[2]).'x',
            $text,
        ) ?? $text;
        $text = preg_replace_callback(
            '/(\d+)\*(\d+)x/',
            static fn (array $m): string => ((int) $m[1] * (int) $m[2]).'x',
            $text,
        ) ?? $text;
        $text = preg_replace_callback(
            '/(\d*)x\*(\d+)/',
            static function (array $m): string {
                $a = $m[1] === '' ? 1 : (int) $m[1];

                return ($a * (int) $m[2]).'x';
            },
            $text,
        ) ?? $text;

        $text = str_replace('*', '', $text);

        // Standalone x-20 → -20+x
        if (preg_match('/^x-(\d+)$/', $text, $m)) {
            $text = '-'.$m[1].'+x';
        }

        // (x+100)/2 → x/2+50 ; (2x-100)/2 → x-50  (before dropping parentheses)
        $text = preg_replace_callback(
            '/\((\d*)x([+-]\d+)\)\/(\d+)/',
            static function (array $m): string {
                $coeff = $m[1] === '' ? 1 : (int) $m[1];
                $const = (int) $m[2];
                $den = (int) $m[3];
                if ($den === 0 || $const % $den !== 0) {
                    return $m[0];
                }
                $c = intdiv($const, $den);
                if ($coeff % $den === 0) {
                    $k = intdiv($coeff, $den);
                    $xPart = $k === 1 ? 'x' : $k.'x';
                } else {
                    $xPart = $coeff === 1 ? 'x/'.$den : $coeff.'x/'.$den;
                }

                return $c === 0 ? $xPart : ($c > 0 ? $xPart.'+'.$c : $xPart.$c);
            },
            $text,
        ) ?? $text;

        // 1/2*(x+100) → x/2+50
        $text = preg_replace_callback(
            '/(\d+)\/(\d+)\*\((\d*)x([+-])(\d+)\)/',
            static function (array $m): string {
                $num = (int) $m[1];
                $den = (int) $m[2];
                $coeff = $m[3] === '' ? 1 : (int) $m[3];
                $sign = $m[4] === '-' ? -1 : 1;
                $c = (int) $m[5];
                if ($den === 0 || ($num * $sign * $c) % $den !== 0) {
                    return $m[0];
                }
                $const = intdiv($num * $sign * $c, $den);
                $xNum = $num * $coeff;
                if ($xNum % $den === 0) {
                    $k = intdiv($xNum, $den);
                    $xPart = $k === 1 ? 'x' : $k.'x';
                } else {
                    $xPart = $xNum === 1 ? 'x/'.$den : $xNum.'x/'.$den;
                }

                return $const === 0 ? $xPart : ($const > 0 ? $xPart.'+'.$const : $xPart.$const);
            },
            $text,
        ) ?? $text;

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
