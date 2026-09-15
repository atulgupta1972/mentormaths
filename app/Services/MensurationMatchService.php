<?php

namespace App\Services;

use App\Models\GradeLevel;
use App\Models\MensurationMatchSession;
use App\Models\MensurationMatchSetting;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class MensurationMatchService
{
    public function catalog(): array
    {
        return array_values(config('mensuration_match.items', []));
    }

    public function classNumber(GradeLevel $grade): int
    {
        if (preg_match('/(\d+)/', (string) $grade->name, $m)) {
            return (int) $m[1];
        }

        return (int) ($grade->sort_order ?: 0);
    }

    public function itemAppliesToClass(array $item, int $classNumber): bool
    {
        $classes = $item['classes'] ?? 'all';
        if ($classes === 'all' || $classes === ['all']) {
            return true;
        }

        if (! is_array($classes)) {
            return true;
        }

        return in_array($classNumber, array_map('intval', $classes), true);
    }

    public function settingsForGrade(GradeLevel $grade): MensurationMatchSetting
    {
        return MensurationMatchSetting::query()->firstOrCreate(
            ['grade_level_id' => $grade->id],
            [
                'enabled' => false,
                'perimeter_area_enabled' => true,
                'volume_enabled' => $this->classNumber($grade) >= 8,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminRows(): array
    {
        return GradeLevel::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (GradeLevel $grade) {
                $settings = $this->settingsForGrade($grade);
                $classNumber = $this->classNumber($grade);
                $available = collect($this->catalog())
                    ->filter(fn (array $item) => $this->itemAppliesToClass($item, $classNumber))
                    ->groupBy('board')
                    ->map->count();

                return [
                    'grade_level_id' => $grade->id,
                    'grade_name' => $grade->name,
                    'class_number' => $classNumber,
                    'enabled' => (bool) $settings->enabled,
                    'perimeter_area_enabled' => (bool) $settings->perimeter_area_enabled,
                    'volume_enabled' => (bool) $settings->volume_enabled,
                    'perimeter_area_item_count' => (int) ($available['perimeter_area'] ?? 0),
                    'volume_item_count' => (int) ($available['volume'] ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    public function upsertForGrade(GradeLevel $grade, array $data): MensurationMatchSetting
    {
        $settings = $this->settingsForGrade($grade);
        $settings->fill([
            'enabled' => (bool) ($data['enabled'] ?? false),
            'perimeter_area_enabled' => (bool) ($data['perimeter_area_enabled'] ?? true),
            'volume_enabled' => (bool) ($data['volume_enabled'] ?? false),
        ]);
        $settings->save();

        return $settings->fresh();
    }

    /**
     * @return list<array{key: string, label: string, item_count: int, completed_today: bool}>
     */
    public function boardsForEnrollment(StudentEnrollment $enrollment): array
    {
        $grade = $enrollment->gradeLevel;
        if (! $grade) {
            return [];
        }

        $settings = $this->settingsForGrade($grade);
        if (! $settings->enabled) {
            return [];
        }

        $classNumber = $this->classNumber($grade);
        $today = Carbon::today()->toDateString();
        $boards = [];

        if ($settings->perimeter_area_enabled) {
            $items = $this->itemsForBoard('perimeter_area', $classNumber);
            if ($items !== []) {
                $session = MensurationMatchSession::query()
                    ->where('student_id', $enrollment->student_id)
                    ->whereDate('drill_date', $today)
                    ->where('board', 'perimeter_area')
                    ->first();
                $boards[] = [
                    'key' => 'perimeter_area',
                    'title' => 'Perimeter & Area',
                    'label' => 'Perimeter & Area',
                    'subtitle' => 'Walk around shapes and cover flat areas — match each story to its formula.',
                    'item_count' => count($items),
                    'completed_today' => $session?->status === MensurationMatchSession::STATUS_COMPLETED,
                    'session_id' => $session?->id,
                ];
            }
        }

        if ($settings->volume_enabled) {
            $items = $this->itemsForBoard('volume', $classNumber);
            if ($items !== []) {
                $session = MensurationMatchSession::query()
                    ->where('student_id', $enrollment->student_id)
                    ->whereDate('drill_date', $today)
                    ->where('board', 'volume')
                    ->first();
                $boards[] = [
                    'key' => 'volume',
                    'title' => 'Volume',
                    'label' => 'Volume',
                    'subtitle' => 'Fill 3D shapes — match each story to its volume formula.',
                    'item_count' => count($items),
                    'completed_today' => $session?->status === MensurationMatchSession::STATUS_COMPLETED,
                    'session_id' => $session?->id,
                ];
            }
        }

        return $boards;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsForBoard(string $board, int $classNumber): array
    {
        return collect($this->catalog())
            ->filter(fn (array $item) => ($item['board'] ?? '') === $board)
            ->filter(fn (array $item) => $this->itemAppliesToClass($item, $classNumber))
            ->values()
            ->map(fn (array $item) => [
                'key' => $item['key'],
                'measure' => $item['measure'],
                'figure' => $item['figure'],
                'title' => $item['title'],
                'story' => $item['story'],
                'formula' => $item['formula'],
                'diagram' => $item['diagram'] ?? $item['figure'],
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    public function formulaBankForItems(array $items): array
    {
        $formulas = collect($items)->pluck('formula')->unique()->values()->all();
        // Keep order stable; bank = exact set of answers for this board.
        return $formulas;
    }

    public function startBoard(Student $student, StudentEnrollment $enrollment, string $board): MensurationMatchSession
    {
        if (! in_array($board, ['perimeter_area', 'volume'], true)) {
            throw new InvalidArgumentException('Unknown board.');
        }

        $grade = $enrollment->gradeLevel;
        if (! $grade) {
            throw new InvalidArgumentException('No class on enrollment.');
        }

        $settings = $this->settingsForGrade($grade);
        if (! $settings->enabled) {
            throw new InvalidArgumentException('Mensuration Match is not enabled for your class yet.');
        }

        if ($board === 'perimeter_area' && ! $settings->perimeter_area_enabled) {
            throw new InvalidArgumentException('Perimeter & Area board is not enabled for your class.');
        }
        if ($board === 'volume' && ! $settings->volume_enabled) {
            throw new InvalidArgumentException('Volume board is not enabled for your class.');
        }

        $classNumber = $this->classNumber($grade);
        $items = $this->itemsForBoard($board, $classNumber);
        if ($items === []) {
            throw new InvalidArgumentException('No mensuration items for your class on this board.');
        }

        $today = Carbon::today()->toDateString();
        $session = MensurationMatchSession::query()->firstOrNew([
            'student_id' => $student->id,
            'drill_date' => $today,
            'board' => $board,
        ]);

        if ($session->exists && $session->status === MensurationMatchSession::STATUS_COMPLETED) {
            return $session;
        }

        $session->fill([
            'student_enrollment_id' => $enrollment->id,
            'status' => MensurationMatchSession::STATUS_IN_PROGRESS,
            'total_items' => count($items),
            'correct_count' => 0,
            'item_keys' => array_column($items, 'key'),
            'answers' => [],
            'started_at' => now(),
            'completed_at' => null,
        ]);
        $session->save();

        return $session->fresh();
    }

    public function submitAnswer(MensurationMatchSession $session, string $itemKey, string $formula): array
    {
        if ($session->status === MensurationMatchSession::STATUS_COMPLETED) {
            throw new InvalidArgumentException('This board is already finished for today.');
        }

        $keys = $session->item_keys ?? [];
        if (! in_array($itemKey, $keys, true)) {
            throw new InvalidArgumentException('That item is not in this board.');
        }

        $answers = is_array($session->answers) ? $session->answers : [];
        if (isset($answers[$itemKey])) {
            throw new InvalidArgumentException('Already answered.');
        }

        $catalog = collect($this->catalog())->keyBy('key');
        $item = $catalog->get($itemKey);
        if (! $item) {
            throw new InvalidArgumentException('Unknown item.');
        }

        $correctFormula = (string) ($item['formula'] ?? '');
        $isCorrect = $this->normalizeFormula($formula) === $this->normalizeFormula($correctFormula);

        // Only lock a story when the match is correct; wrong taps stay retryable.
        if ($isCorrect) {
            $answers[$itemKey] = [
                'formula' => $formula,
                'correct' => true,
                'expected' => $correctFormula,
                'at' => now()->toIso8601String(),
            ];
        }

        $correctCount = collect($answers)->where('correct', true)->count();
        $done = $correctCount >= count($keys);

        $session->update([
            'answers' => $answers,
            'correct_count' => $correctCount,
            'status' => $done ? MensurationMatchSession::STATUS_COMPLETED : MensurationMatchSession::STATUS_IN_PROGRESS,
            'completed_at' => $done ? now() : null,
        ]);

        return [
            'correct' => $isCorrect,
            'expected' => $correctFormula,
            'explanation' => $this->flashLine($item),
            'done' => $done,
            'correct_count' => $correctCount,
            'total' => count($keys),
        ];
    }

    public function playPayload(MensurationMatchSession $session, StudentEnrollment $enrollment): array
    {
        $grade = $enrollment->gradeLevel;
        $classNumber = $grade ? $this->classNumber($grade) : 0;
        $items = $this->itemsForBoard($session->board, $classNumber);

        return $this->buildPlayPayload(
            board: $session->board,
            items: $items,
            answers: is_array($session->answers) ? $session->answers : [],
            status: $session->status,
            correctCount: (int) $session->correct_count,
            totalItems: (int) $session->total_items,
            sessionId: $session->id,
        );
    }

    /**
     * Session-backed preview so admins can try a class board without a student record.
     *
     * @return array<string, mixed>
     */
    public function startAdminPreview(GradeLevel $grade, string $board): array
    {
        if (! in_array($board, ['perimeter_area', 'volume'], true)) {
            throw new InvalidArgumentException('Unknown board.');
        }

        $items = $this->itemsForBoard($board, $this->classNumber($grade));
        if ($items === []) {
            throw new InvalidArgumentException('No mensuration items for this class on this board.');
        }

        return [
            'grade_level_id' => $grade->id,
            'grade_name' => $grade->name,
            'board' => $board,
            'status' => MensurationMatchSession::STATUS_IN_PROGRESS,
            'total_items' => count($items),
            'correct_count' => 0,
            'item_keys' => array_column($items, 'key'),
            'answers' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, result: array<string, mixed>}
     */
    public function submitAdminPreviewAnswer(array $state, string $itemKey, string $formula): array
    {
        if (($state['status'] ?? '') === MensurationMatchSession::STATUS_COMPLETED) {
            throw new InvalidArgumentException('This preview board is already finished. Start again to retry.');
        }

        $keys = $state['item_keys'] ?? [];
        if (! in_array($itemKey, $keys, true)) {
            throw new InvalidArgumentException('That item is not in this board.');
        }

        $answers = is_array($state['answers'] ?? null) ? $state['answers'] : [];
        if (isset($answers[$itemKey])) {
            throw new InvalidArgumentException('Already answered.');
        }

        $catalog = collect($this->catalog())->keyBy('key');
        $item = $catalog->get($itemKey);
        if (! $item) {
            throw new InvalidArgumentException('Unknown item.');
        }

        $correctFormula = (string) ($item['formula'] ?? '');
        $isCorrect = $this->normalizeFormula($formula) === $this->normalizeFormula($correctFormula);

        if ($isCorrect) {
            $answers[$itemKey] = [
                'formula' => $formula,
                'correct' => true,
                'expected' => $correctFormula,
                'at' => now()->toIso8601String(),
            ];
        }

        $correctCount = collect($answers)->where('correct', true)->count();
        $done = $correctCount >= count($keys);

        $state['answers'] = $answers;
        $state['correct_count'] = $correctCount;
        $state['status'] = $done ? MensurationMatchSession::STATUS_COMPLETED : MensurationMatchSession::STATUS_IN_PROGRESS;

        return [
            'state' => $state,
            'result' => [
                'correct' => $isCorrect,
                'expected' => $correctFormula,
                'explanation' => $this->flashLine($item),
                'done' => $done,
                'correct_count' => $correctCount,
                'total' => count($keys),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function adminPlayPayload(array $state): array
    {
        $grade = GradeLevel::query()->find($state['grade_level_id'] ?? null);
        $classNumber = $grade ? $this->classNumber($grade) : 0;
        $board = (string) ($state['board'] ?? 'perimeter_area');
        $items = $this->itemsForBoard($board, $classNumber);

        return $this->buildPlayPayload(
            board: $board,
            items: $items,
            answers: is_array($state['answers'] ?? null) ? $state['answers'] : [],
            status: (string) ($state['status'] ?? MensurationMatchSession::STATUS_IN_PROGRESS),
            correctCount: (int) ($state['correct_count'] ?? 0),
            totalItems: (int) ($state['total_items'] ?? count($items)),
            sessionId: null,
            gradeName: $grade?->name ?? ($state['grade_name'] ?? null),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function buildPlayPayload(
        string $board,
        array $items,
        array $answers,
        string $status,
        int $correctCount,
        int $totalItems,
        ?int $sessionId = null,
        ?string $gradeName = null,
    ): array {
        $playItems = collect($items)->map(function (array $item) use ($answers) {
            $answer = $answers[$item['key']] ?? null;
            $matched = is_array($answer) && ! empty($answer['correct']);

            return [
                'key' => $item['key'],
                'title' => $item['title'] ?? '',
                'story' => $item['story'] ?? '',
                'diagram' => $item['diagram'] ?? $item['figure'] ?? 'circle_walk',
                'hint' => $item['measure'] ?? null,
                'matched' => $matched,
                'matched_formula' => $matched ? (string) ($answer['formula'] ?? $item['formula']) : null,
            ];
        })->values()->all();

        return [
            'session_id' => $sessionId,
            'board' => $board,
            'board_title' => $board === 'volume' ? 'Volume' : 'Perimeter & Area',
            'grade_name' => $gradeName,
            'status' => $status,
            'score' => $correctCount.'/'.$totalItems,
            'formulas' => $this->formulaBankForItems($items),
            'items' => $playItems,
        ];
    }

    private function normalizeFormula(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace([' ', '×', '·', '*', '²', '³', '½', '⁴⁄₃', '4/3', '1/2'], ['', 'x', 'x', 'x', '2', '3', '1/2', '4/3', '4/3', '1/2'], $value);
        $value = str_replace(['π', 'pi'], 'pi', $value);

        return $value;
    }

    private function flashLine(array $item): string
    {
        $measure = ucfirst((string) ($item['measure'] ?? ''));
        $title = (string) ($item['title'] ?? $item['figure'] ?? '');
        $formula = (string) ($item['formula'] ?? '');

        return "{$measure} — {$title}: {$formula}";
    }
}
