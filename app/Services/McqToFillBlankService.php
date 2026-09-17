<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class McqToFillBlankService
{
    /**
     * @return array{convertible: bool, answer: ?string, answer_format: ?string, decimal_places: ?int, reason: ?string}
     */
    public function inspect(Question $question): array
    {
        if ($question->isFillInBlank()) {
            return [
                'convertible' => false,
                'answer' => $question->blankAnswer?->correct_answer,
                'answer_format' => $question->blankAnswer?->answer_format,
                'decimal_places' => $question->blankAnswer?->decimal_places,
                'reason' => 'Already fill-in-blank',
            ];
        }

        if (! $question->isMcq()) {
            return [
                'convertible' => false,
                'answer' => null,
                'answer_format' => null,
                'decimal_places' => null,
                'reason' => 'Not an MCQ',
            ];
        }

        $question->loadMissing('options');
        $correct = $question->options->firstWhere('is_correct', true);
        if (! $correct) {
            return [
                'convertible' => false,
                'answer' => null,
                'answer_format' => null,
                'decimal_places' => null,
                'reason' => 'No correct option',
            ];
        }

        $parsed = $this->parseNumericAnswer((string) $correct->option_text);
        if ($parsed === null) {
            return [
                'convertible' => false,
                'answer' => trim((string) $correct->option_text),
                'answer_format' => null,
                'decimal_places' => null,
                'reason' => 'Answer is not a whole number / decimal / fraction',
            ];
        }

        return [
            'convertible' => true,
            'answer' => $parsed['correct_answer'],
            'answer_format' => $parsed['answer_format'],
            'decimal_places' => $parsed['decimal_places'],
            'reason' => null,
        ];
    }

    public function convert(Question $question): Question
    {
        $inspect = $this->inspect($question);
        if (! $inspect['convertible']) {
            throw new InvalidArgumentException($inspect['reason'] ?? 'Cannot convert this question.');
        }

        return DB::transaction(function () use ($question, $inspect) {
            $stem = trim((string) $question->question_text);
            if ($stem !== '' && ! str_contains($stem, '____')) {
                $stem = rtrim($stem, " \t\n\r\0\x0B.?").' = ____';
            }

            $question->update([
                'type' => Question::TYPE_FILL_IN_BLANK,
                'question_text' => $stem,
            ]);

            $question->blankAnswer()->updateOrCreate(
                ['question_id' => $question->id],
                [
                    'answer_format' => $inspect['answer_format'],
                    'correct_answer' => $inspect['answer'],
                    'decimal_places' => $inspect['decimal_places'],
                ],
            );

            QuestionOption::query()->where('question_id', $question->id)->delete();

            return $question->fresh(['blankAnswer', 'options']);
        });
    }

    /**
     * @param  list<int>  $questionIds
     * @return array{converted: int, skipped: int, errors: list<string>}
     */
    public function convertMany(array $questionIds): array
    {
        $converted = 0;
        $skipped = 0;
        $errors = [];

        $questions = Question::query()
            ->with(['options', 'blankAnswer'])
            ->whereIn('id', $questionIds)
            ->get()
            ->keyBy('id');

        foreach ($questionIds as $id) {
            $question = $questions->get($id);
            if (! $question) {
                $skipped++;
                $errors[] = "Question #{$id} not found.";

                continue;
            }

            $inspect = $this->inspect($question);
            if (! $inspect['convertible']) {
                $skipped++;
                if ($inspect['reason'] && $inspect['reason'] !== 'Already fill-in-blank') {
                    $errors[] = "Q{$id}: {$inspect['reason']}";
                }

                continue;
            }

            $this->convert($question);
            $converted++;
        }

        return [
            'converted' => $converted,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Convert convertible MCQs in a collection; returns updated question models keyed by original id.
     *
     * @param  Collection<int, Question>  $questions
     * @return Collection<int, Question>
     */
    public function convertConvertibleInCollection(Collection $questions): Collection
    {
        return $questions->map(function (Question $question) {
            $inspect = $this->inspect($question);
            if (! $inspect['convertible']) {
                return $question;
            }

            return $this->convert($question);
        });
    }

    /**
     * @return array{correct_answer: string, answer_format: string, decimal_places: ?int}|null
     */
    public function parseNumericAnswer(string $raw): ?array
    {
        $answer = trim(str_replace([',', ' '], '', $raw));
        if ($answer === '') {
            return null;
        }

        if (preg_match('/^-?\d+\/\d+$/', $answer)) {
            return [
                'correct_answer' => $answer,
                'answer_format' => QuestionBlankAnswer::FORMAT_FRACTION,
                'decimal_places' => null,
            ];
        }

        if (preg_match('/^-?\d+\.\d+$/', $answer)) {
            $places = strlen(substr(strrchr($answer, '.') ?: '', 1));

            return [
                'correct_answer' => $answer,
                'answer_format' => QuestionBlankAnswer::FORMAT_DECIMAL,
                'decimal_places' => $places,
            ];
        }

        if (preg_match('/^-?\d+$/', $answer)) {
            return [
                'correct_answer' => $answer,
                'answer_format' => QuestionBlankAnswer::FORMAT_INTEGER,
                'decimal_places' => null,
            ];
        }

        return null;
    }
}
