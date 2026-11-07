<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TextbookChapter;
use App\Services\TextbookChapterMcqImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TextbookStudentTryController extends Controller
{
    private const SESSION_KEY = 'textbook_student_try';

    public function __construct(
        private TextbookChapterMcqImportService $mcqImport,
    ) {}

    public function show(Request $request, TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        $range = $this->range($request, $textbookChapter);
        if ($range instanceof RedirectResponse) {
            return $range;
        }

        [$from, $to, $setCode, $items] = $range;
        $state = $this->state($request, $textbookChapter, $from, $to, $setCode);

        if ($request->boolean('restart')) {
            $state = $this->freshState($textbookChapter, $from, $to, $setCode);
            $request->session()->put(self::SESSION_KEY, $state);

            return redirect()->route('admin.textbooks.try-student', $this->query($textbookChapter, $from, $to, $setCode));
        }

        if ($request->boolean('next')) {
            if (($state['feedback'] ?? null) !== null) {
                $state['index'] = (int) $state['index'] + 1;
                $state['feedback'] = null;
                $request->session()->put(self::SESSION_KEY, $state);
            }

            return redirect()->route('admin.textbooks.try-student', $this->query($textbookChapter, $from, $to, $setCode));
        }

        return Inertia::render('Admin/Textbooks/TryStudent', [
            'chapter' => [
                'id' => $textbookChapter->id,
                'title' => $textbookChapter->displayTitle(),
            ],
            'play' => $this->playPayload($state, $items, $from, $setCode),
            'urls' => [
                'answer' => route('admin.textbooks.try-student.answer', $this->query($textbookChapter, $from, $to, $setCode)),
                'next' => route('admin.textbooks.try-student', [...$this->query($textbookChapter, $from, $to, $setCode), 'next' => 1]),
                'restart' => route('admin.textbooks.try-student', [...$this->query($textbookChapter, $from, $to, $setCode), 'restart' => 1]),
                'back' => route('admin.textbooks.show', $textbookChapter),
            ],
        ]);
    }

    public function answer(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $range = $this->range($request, $textbookChapter);
        if ($range instanceof RedirectResponse) {
            return $range;
        }

        [$from, $to, $setCode, $items] = $range;
        $state = $this->state($request, $textbookChapter, $from, $to, $setCode);
        $index = (int) ($state['index'] ?? 0);

        if (($state['feedback'] ?? null) !== null || ! isset($items[$index])) {
            return redirect()->route('admin.textbooks.try-student', $this->query($textbookChapter, $from, $to, $setCode));
        }

        $validated = $request->validate([
            'answer_text' => ['nullable', 'string', 'max:200'],
            'option_index' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        $item = $items[$index];
        $fillBlank = ($item['question_type'] ?? '') === 'fill_blank';
        $expected = $this->expectedAnswer($item);
        $correct = false;
        $given = '';

        if ($fillBlank) {
            $given = trim((string) ($validated['answer_text'] ?? ''));
            if ($given === '') {
                return back()->with('error', 'Type an answer first.');
            }
            $correct = $this->answersMatch($given, $expected);
        } else {
            if (! isset($validated['option_index'])) {
                return back()->with('error', 'Choose an option first.');
            }
            $options = array_values($item['mcq_options'] ?? []);
            $chosen = (int) $validated['option_index'];
            $given = (string) ($options[$chosen]['text'] ?? '');
            $correct = (bool) ($options[$chosen]['is_correct'] ?? false);
        }

        $state['answered'] = (int) ($state['answered'] ?? 0) + 1;
        if ($correct) {
            $state['score'] = (int) ($state['score'] ?? 0) + 1;
        }
        $state['feedback'] = [
            'correct' => $correct,
            'given' => $given,
            'expected' => $expected,
            'hint' => trim((string) ($item['fill_blank_method_hint'] ?? $item['method_hint'] ?? '')),
            'explanation' => trim((string) ($item['fill_blank_explanation'] ?? $item['explanation'] ?? '')),
        ];
        $request->session()->put(self::SESSION_KEY, $state);

        return redirect()->route('admin.textbooks.try-student', $this->query($textbookChapter, $from, $to, $setCode));
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: list<array<string, mixed>>}|RedirectResponse
     */
    private function range(Request $request, TextbookChapter $chapter): array|RedirectResponse
    {
        $raw = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];
        $items = $this->mcqImport->itemsWithDiagramPreviewUrls($raw);
        $count = count($items);

        if ($count === 0) {
            return redirect()
                ->route('admin.textbooks.show', $chapter)
                ->with('error', 'Import the questions first, then try them as a student.');
        }

        $from = max(1, (int) $request->query('from', 1));
        $to = (int) $request->query('to', $count);
        $to = min(max($from, $to), $count);
        $slice = array_values(array_slice($items, $from - 1, $to - $from + 1));

        if ($slice === []) {
            return redirect()
                ->route('admin.textbooks.show', $chapter)
                ->with('error', 'That question range is empty.');
        }

        return [$from, $from + count($slice) - 1, trim((string) $request->query('set', '')), $slice];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Request $request, TextbookChapter $chapter, int $from, int $to, string $setCode): array
    {
        $state = $request->session()->get(self::SESSION_KEY);
        $matches = is_array($state)
            && (int) ($state['chapter_id'] ?? 0) === $chapter->id
            && (int) ($state['from'] ?? 0) === $from
            && (int) ($state['to'] ?? 0) === $to;

        if (! $matches) {
            $state = $this->freshState($chapter, $from, $to, $setCode);
            $request->session()->put(self::SESSION_KEY, $state);
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function freshState(TextbookChapter $chapter, int $from, int $to, string $setCode): array
    {
        return [
            'chapter_id' => $chapter->id,
            'from' => $from,
            'to' => $to,
            'set_code' => $setCode,
            'index' => 0,
            'score' => 0,
            'answered' => 0,
            'feedback' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function playPayload(array $state, array $items, int $from, string $setCode): array
    {
        $index = (int) ($state['index'] ?? 0);
        $total = count($items);
        $done = $index >= $total;
        $item = $done ? null : $items[$index];
        $feedback = $state['feedback'] ?? null;

        $question = null;
        if (is_array($item)) {
            $fillBlank = ($item['question_type'] ?? '') === 'fill_blank';
            $text = $fillBlank
                ? (string) ($item['fill_blank_question_text'] ?? $item['question_text'] ?? '')
                : (string) ($item['question_text'] ?? '');
            $options = [];
            if (! $fillBlank) {
                foreach (array_values($item['mcq_options'] ?? []) as $option) {
                    $optionText = trim((string) ($option['text'] ?? ''));
                    if ($optionText !== '') {
                        $options[] = ['text' => $optionText];
                    }
                }
            }
            $question = [
                'number' => $from + $index,
                'label' => (string) ($item['label'] ?? $item['topic'] ?? ''),
                'text' => $text,
                'type' => $fillBlank ? 'fill_blank' : 'mcq',
                'options' => $options,
                'diagram_url' => $item['diagram_preview_url'] ?? null,
                'answer_unit' => $item['answer_unit'] ?? null,
            ];
        }

        return [
            'status' => $done ? 'completed' : 'in_progress',
            'index' => min($index + 1, $total),
            'total' => $total,
            'score' => (int) ($state['score'] ?? 0),
            'answered' => (int) ($state['answered'] ?? 0),
            'set_code' => $setCode,
            'from' => (int) ($state['from'] ?? $from),
            'to' => (int) ($state['to'] ?? $from),
            'question' => $question,
            'feedback' => is_array($feedback) ? $feedback : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function expectedAnswer(array $item): string
    {
        $fill = trim((string) ($item['fill_blank_correct_answer'] ?? ''));
        if ($fill !== '') {
            return $fill;
        }

        $correct = collect($item['mcq_options'] ?? [])->firstWhere('is_correct', true);

        return trim((string) ($correct['text'] ?? $item['correct_answer'] ?? ''));
    }

    private function answersMatch(string $given, string $expected): bool
    {
        $given = $this->normalize($given);
        $expected = $this->normalize($expected);

        if ($given === '' || $expected === '') {
            return false;
        }

        if ($given === $expected) {
            return true;
        }

        $givenValue = $this->numericValue($given);
        $expectedValue = $this->numericValue($expected);

        return $givenValue !== null
            && $expectedValue !== null
            && abs($givenValue - $expectedValue) < 0.0001;
    }

    private function normalize(string $value): string
    {
        $value = str_replace(["\u{2212}", '–', '—'], '-', trim($value));
        $value = str_replace([',', ' '], '', $value);

        return strtolower($value);
    }

    private function numericValue(string $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (preg_match('/^(-?\d+)\/(-?\d+)$/', $value, $match) && (int) $match[2] !== 0) {
            return ((int) $match[1]) / ((int) $match[2]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function query(TextbookChapter $chapter, int $from, int $to, string $setCode): array
    {
        return [
            'textbookChapter' => $chapter,
            'from' => $from,
            'to' => $to,
            'set' => $setCode,
        ];
    }
}
