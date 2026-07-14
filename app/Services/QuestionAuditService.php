<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionSetAudit;
use App\Models\User;
use App\Models\Worksheet;

class QuestionAuditService
{
    /**
     * @return array{
     *     status: string,
     *     issue_count: int,
     *     findings: list<array<string, mixed>>
     * }
     */
    public function auditWorksheet(Worksheet $worksheet): array
    {
        $worksheet->load([
            'questions.options',
            'questions.blankAnswer',
        ]);

        $questions = $worksheet->questions
            ->sortBy(fn (Question $question) => $question->pivot->sort_order ?? $question->id)
            ->values();

        $findings = [];

        foreach ($questions as $index => $question) {
            $findings = array_merge(
                $findings,
                $this->auditQuestion($question, $index + 1),
            );
        }

        return [
            'status' => $findings === []
                ? QuestionSetAudit::STATUS_CLEAN
                : QuestionSetAudit::STATUS_ISSUES,
            'issue_count' => count($findings),
            'findings' => $findings,
        ];
    }

    public function recordAudit(Worksheet $worksheet, User $auditor, array $result): QuestionSetAudit
    {
        return QuestionSetAudit::create([
            'worksheet_id' => $worksheet->id,
            'audited_by' => $auditor->id,
            'status' => $result['status'],
            'issue_count' => $result['issue_count'],
            'findings' => $result['findings'],
        ]);
    }

    /**
     * @return list<Worksheet>
     */
    public function packagedWorksheetsForChapter(int $chapterId): \Illuminate\Support\Collection
    {
        return Worksheet::query()
            ->with(['latestAudit.auditor:id,name', 'topic:id,name'])
            ->withCount('questions')
            ->where(function ($query) use ($chapterId) {
                $query->where('syllabus_chapter_id', $chapterId)
                    ->orWhereHas('topic', fn ($topicQuery) => $topicQuery->where('syllabus_chapter_id', $chapterId));
            })
            ->orderBy('set_number')
            ->orderBy('set_code')
            ->get();
    }

    /**
     * @return array{
     *     total_sets: int,
     *     not_audited: int,
     *     clean: int,
     *     issues: int
     * }
     */
    public function chapterAuditSummary(int $chapterId): array
    {
        $sets = $this->packagedWorksheetsForChapter($chapterId);

        $notAudited = 0;
        $clean = 0;
        $issues = 0;

        foreach ($sets as $set) {
            $audit = $set->latestAudit;

            if (! $audit) {
                $notAudited++;

                continue;
            }

            if ($audit->status === QuestionSetAudit::STATUS_CLEAN) {
                $clean++;
            } else {
                $issues++;
            }
        }

        return [
            'total_sets' => $sets->count(),
            'not_audited' => $notAudited,
            'clean' => $clean,
            'issues' => $issues,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditQuestion(Question $question, int $questionNumber): array
    {
        $findings = [];
        $base = [
            'question_id' => $question->id,
            'question_number' => $questionNumber,
            'question_text' => $question->question_text,
            'type' => $question->type,
            'type_label' => $question->isFillInBlank() ? 'Fill in blank' : 'MCQ',
        ];

        if (trim($question->question_text) === '') {
            $findings[] = $this->finding($base, 'empty_question', 'Question text is empty.', 'question_text');
        }

        if ($this->hasGarbledText($question->question_text)) {
            $findings[] = $this->finding($base, 'garbled_text', 'Question text may contain unreadable characters.', 'question_text', $question->question_text);
        }

        if ($question->isFillInBlank()) {
            $findings = array_merge($findings, $this->auditFillBlank($question, $base));
        } else {
            $findings = array_merge($findings, $this->auditMcq($question, $base));
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return list<array<string, mixed>>
     */
    private function auditFillBlank(Question $question, array $base): array
    {
        $findings = [];
        $blank = $question->blankAnswer;

        if (! $blank || trim($blank->correct_answer) === '') {
            $findings[] = $this->finding($base, 'missing_answer', 'Fill-in-blank question has no stored answer.', 'correct_answer');

            return $findings;
        }

        if ($this->hasGarbledText($blank->correct_answer)) {
            $findings[] = $this->finding($base, 'garbled_text', 'Stored answer may contain unreadable characters.', 'correct_answer', $blank->correct_answer);
        }

        if ($blank->answer_format === QuestionBlankAnswer::FORMAT_INTEGER
            && ! preg_match('/^-?\d+$/', trim($blank->correct_answer))) {
            $findings[] = $this->finding(
                $base,
                'invalid_integer_answer',
                'Integer answer should contain digits only.',
                'correct_answer',
                $blank->correct_answer,
            );
        }

        $computed = $this->trySolveLinearEquation($question->question_text);

        if ($computed !== null && ! $this->answersEquivalent($blank->correct_answer, (string) $computed, $blank->answer_format)) {
            $findings[] = $this->finding(
                $base,
                'answer_mismatch',
                "Stored answer is {$blank->correct_answer}, but solving the equation suggests {$computed}.",
                'correct_answer',
                $blank->correct_answer,
                ['suggested_answer' => (string) $computed],
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return list<array<string, mixed>>
     */
    private function auditMcq(Question $question, array $base): array
    {
        $findings = [];
        $options = $question->options;
        $correctOptions = $options->where('is_correct', true);

        if ($options->isEmpty()) {
            $findings[] = $this->finding($base, 'missing_options', 'MCQ has no options.', 'options');

            return $findings;
        }

        if ($correctOptions->isEmpty()) {
            $findings[] = $this->finding($base, 'no_correct_option', 'No option is marked correct.', 'options');
        } elseif ($correctOptions->count() > 1) {
            $findings[] = $this->finding($base, 'multiple_correct_options', 'More than one option is marked correct.', 'options');
        }

        foreach ($options as $option) {
            if (trim($option->option_text) === '') {
                $findings[] = $this->finding($base, 'empty_option', 'An option text is empty.', 'options', $option->option_text);
            } elseif ($this->hasGarbledText($option->option_text)) {
                $findings[] = $this->finding($base, 'garbled_text', 'An option may contain unreadable characters.', 'options', $option->option_text);
            }
        }

        $normalized = $options
            ->map(fn ($option) => strtolower(trim($option->option_text)))
            ->filter()
            ->values();

        if ($normalized->count() !== $normalized->unique()->count()) {
            $findings[] = $this->finding($base, 'duplicate_options', 'Two or more options have the same text.', 'options');
        }

        $correctOption = $correctOptions->first();

        if ($correctOption && trim($correctOption->option_text) === '') {
            $findings[] = $this->finding($base, 'empty_correct_option', 'The correct option text is empty.', 'correct_answer');
        }

        $computed = $this->trySolveLinearEquation($question->question_text);

        if ($computed !== null && $correctOption && ! $this->answersEquivalent($correctOption->option_text, (string) $computed, QuestionBlankAnswer::FORMAT_INTEGER)) {
            $findings[] = $this->finding(
                $base,
                'answer_mismatch',
                "Marked answer is {$correctOption->option_text}, but solving the equation suggests {$computed}.",
                'correct_answer',
                $correctOption->option_text,
                ['suggested_answer' => (string) $computed],
            );
        }

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function finding(
        array $base,
        string $issueType,
        string $message,
        string $field,
        ?string $currentValue = null,
        array $extra = [],
    ): array {
        return array_merge($base, [
            'issue_type' => $issueType,
            'message' => $message,
            'field' => $field,
            'current_value' => $currentValue,
        ], $extra);
    }

    private function hasGarbledText(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        if (str_contains($text, '�')) {
            return true;
        }

        return (bool) preg_match('/(?:Ã.|â[\x80-\xBF]|ï¿½)/u', $text);
    }

    private function answersEquivalent(string $stored, string $expected, ?string $format): bool
    {
        $stored = trim($stored);
        $expected = trim($expected);

        if ($stored === $expected) {
            return true;
        }

        if (is_numeric($stored) && is_numeric($expected)) {
            return abs((float) $stored - (float) $expected) < 0.0001;
        }

        return strcasecmp($stored, $expected) === 0;
    }

    private function trySolveLinearEquation(string $questionText): ?int
    {
        $text = trim(preg_replace('/\s+/u', ' ', $questionText) ?? $questionText);

        if (preg_match('/(-?\d+)\s*x\s*([-+])\s*(\d+)\s*=\s*(-?\d+)/iu', $text, $matches)) {
            $a = (int) $matches[1];
            $operator = $matches[2];
            $b = (int) $matches[3];
            $c = (int) $matches[4];

            if ($a === 0) {
                return null;
            }

            $x = $operator === '+'
                ? ($c - $b) / $a
                : ($c + $b) / $a;

            return fmod($x, 1.0) === 0.0 ? (int) $x : null;
        }

        if (preg_match('/x\s*([-+])\s*(\d+)\s*=\s*(-?\d+)/iu', $text, $matches)) {
            $operator = $matches[1];
            $b = (int) $matches[2];
            $c = (int) $matches[3];
            $x = $operator === '+' ? $c - $b : $c + $b;

            return $x;
        }

        if (preg_match('/(-?\d+)\s*x\s*=\s*(-?\d+)/iu', $text, $matches)) {
            $a = (int) $matches[1];
            $c = (int) $matches[2];

            if ($a === 0) {
                return null;
            }

            $x = $c / $a;

            return fmod($x, 1.0) === 0.0 ? (int) $x : null;
        }

        return null;
    }
}
