<?php

namespace App\Services;

use App\Models\QuestionResolutionItem;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\AnswerValidationService;
use App\Support\DateLabels;
use App\Support\DoubtsClearedMailer;
use Illuminate\Support\Collection;

class QuestionResolutionService
{
    public function __construct(
        private AnswerValidationService $answerValidation,
    ) {}
    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForEnrollment(int $enrollmentId): array
    {
        return QuestionResolutionItem::query()
            ->with([
                'question.options',
                'assignment.practiceSet:id,set_code,set_number',
            ])
            ->where('student_enrollment_id', $enrollmentId)
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->orderByDesc('gave_up_at')
            ->get()
            ->map(fn (QuestionResolutionItem $item) => $this->formatItem($item))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatItem(QuestionResolutionItem $item): array
    {
        $item->loadMissing(['question.options', 'question.blankAnswer', 'assignment.practiceSet']);

        return [
            'id' => $item->id,
            'question_id' => $item->question_id,
            'question_type' => $item->question->type,
            'answer_format' => $item->question->blankAnswer?->answer_format,
            'answer_format_label' => $this->answerValidation->formatLabel($item->question->blankAnswer?->answer_format),
            'gave_up_at' => $item->gave_up_at?->toDateTimeString(),
            'set_code' => $item->assignment?->practiceSet?->set_code,
            'question_text' => $item->question->question_text,
            'diagram_url' => $item->question->diagram_url,
            'options' => $item->question->isMcq()
                ? $item->question->options->values()->map(function ($option, $index) {
                    return [
                        'id' => $option->id,
                        'letter' => chr(65 + $index),
                        'option_text' => $option->option_text,
                    ];
                })->all()
                : [],
        ];
    }

    /**
     * @return array{correct: bool, message: string, resolved: bool}
     */
    public function submitAnswer(QuestionResolutionItem $item, ?int $optionId = null, ?string $answerText = null): array
    {
        if ($item->status !== QuestionResolutionItem::STATUS_PENDING) {
            throw new \InvalidArgumentException('This question is already resolved.');
        }

        $item->loadMissing(['question.options', 'question.blankAnswer']);

        if ($item->question->isFillInBlank()) {
            if (! filled($answerText)) {
                throw new \InvalidArgumentException('Enter an answer before submitting.');
            }

            if (! $this->answerValidation->isCorrect($item->question, $answerText)) {
                return [
                    'correct' => false,
                    'resolved' => false,
                    'message' => 'Not correct yet. Ask your teacher if you are stuck, then try again.',
                ];
            }
        } else {
            $option = $item->question->options->firstWhere('id', $optionId);

            if (! $option) {
                throw new \InvalidArgumentException('Invalid option selected.');
            }

            if (! $option->is_correct) {
                return [
                    'correct' => false,
                    'resolved' => false,
                    'message' => 'Not correct yet. Ask your teacher if you are stuck, then try again.',
                ];
            }
        }

        $item->update([
            'status' => QuestionResolutionItem::STATUS_RESOLVED,
            'resolved_at' => now(),
            'clearance_method' => QuestionResolutionItem::CLEARANCE_ANSWERED,
        ]);

        return [
            'correct' => true,
            'resolved' => true,
            'message' => 'Well done — this sum is cleared from your help list.',
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public function sendClearanceEmailForItems(Student $student, array $itemIds): array
    {
        if ($itemIds === []) {
            return ['sent' => false, 'email' => null, 'error' => 'no_items'];
        }

        $items = QuestionResolutionItem::query()
            ->with([
                'question.topic.chapter',
                'assignment.practiceSet',
            ])
            ->whereIn('id', $itemIds)
            ->orderByDesc('resolved_at')
            ->get()
            ->map(fn (QuestionResolutionItem $item) => $this->formatEmailItem($item))
            ->values()
            ->all();

        return DoubtsClearedMailer::send($student, $items);
    }

    public function firstPendingForEnrollment(int $enrollmentId): ?QuestionResolutionItem
    {
        return $this->pendingQueryForEnrollment($enrollmentId)->first();
    }

    public function nextPendingAfter(int $enrollmentId, int $currentItemId): ?QuestionResolutionItem
    {
        $ids = $this->pendingQueryForEnrollment($enrollmentId)->pluck('id');
        $index = $ids->search($currentItemId);

        if ($index === false) {
            return $this->firstPendingForEnrollment($enrollmentId);
        }

        $nextId = $ids->get($index + 1);

        return $nextId
            ? QuestionResolutionItem::query()->find($nextId)
            : null;
    }

    /**
     * @return array{position: int, total: int}
     */
    public function queueMetaForItem(QuestionResolutionItem $item): array
    {
        $ids = $this->pendingQueryForEnrollment($item->student_enrollment_id)->pluck('id');
        $index = $ids->search($item->id);

        return [
            'position' => $index === false ? 1 : $index + 1,
            'total' => $ids->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForEnrollment(int $enrollmentId): array
    {
        return QuestionResolutionItem::query()
            ->with([
                'question.topic.chapter',
                'assignment.practiceSet:id,set_code,set_number',
            ])
            ->where('student_enrollment_id', $enrollmentId)
            ->where('status', QuestionResolutionItem::STATUS_RESOLVED)
            ->orderByDesc('resolved_at')
            ->get()
            ->map(fn (QuestionResolutionItem $item) => $this->formatHistoryItem($item))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatHistoryItem(QuestionResolutionItem $item): array
    {
        $item->loadMissing(['question.topic.chapter', 'assignment.practiceSet']);

        return [
            'id' => $item->id,
            'set_code' => $item->assignment?->practiceSet?->set_code,
            'question_text' => $item->question->question_text,
            'topic_label' => $this->topicLabel($item),
            'gave_up_at' => $item->gave_up_at?->toDateTimeString(),
            'gave_up_label' => DateLabels::formatDateTime($item->gave_up_at, '—'),
            'resolved_at' => $item->resolved_at?->toDateTimeString(),
            'resolved_label' => DateLabels::formatDateTime($item->resolved_at, '—'),
            'clearance_method' => $item->clearance_method,
            'clearance_label' => $this->clearanceLabel($item->clearance_method),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEmailItem(QuestionResolutionItem $item): array
    {
        return [
            'set_code' => $item->assignment?->practiceSet?->set_code,
            'question_text' => $item->question->question_text,
            'topic_label' => $this->topicLabel($item),
            'asked_label' => DateLabels::formatDateTime($item->gave_up_at, '—'),
            'cleared_label' => DateLabels::formatDateTime($item->resolved_at, '—'),
        ];
    }

    private function topicLabel(QuestionResolutionItem $item): ?string
    {
        $topic = $item->question->topic?->name;
        $chapter = $item->question->topic?->chapter?->name;

        if ($topic && $chapter) {
            return "{$topic} ({$chapter})";
        }

        return $topic ?: $chapter;
    }

    private function clearanceLabel(?string $method): string
    {
        return match ($method) {
            QuestionResolutionItem::CLEARANCE_ACKNOWLEDGED => 'Marked cleared after teacher help',
            QuestionResolutionItem::CLEARANCE_ANSWERED => 'Answered correctly',
            default => 'Cleared',
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<QuestionResolutionItem>
     */
    private function pendingQueryForEnrollment(int $enrollmentId)
    {
        return QuestionResolutionItem::query()
            ->where('student_enrollment_id', $enrollmentId)
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->orderByDesc('gave_up_at')
            ->orderByDesc('id');
    }

    public function pendingCountForEnrollment(int $enrollmentId): int
    {
        return QuestionResolutionItem::query()
            ->where('student_enrollment_id', $enrollmentId)
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingForStudentIds(array $studentIds, ?int $academicYearId = null): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        $query = QuestionResolutionItem::query()
            ->with([
                'question:id,question_text',
                'enrollment.student:id,name',
                'enrollment.gradeLevel:id,name',
                'assignment.practiceSet:id,set_code',
            ])
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->whereHas('enrollment', function ($q) use ($studentIds, $academicYearId) {
                $q->whereIn('student_id', $studentIds);

                if ($academicYearId) {
                    $q->where('academic_year_id', $academicYearId);
                }
            });

        return $query->orderByDesc('gave_up_at')->get()->map(fn (QuestionResolutionItem $item) => [
            'id' => $item->id,
            'student_id' => $item->enrollment->student_id,
            'student_name' => $item->enrollment->student?->name,
            'class_name' => $item->enrollment->gradeLevel?->name,
            'set_code' => $item->assignment?->practiceSet?->set_code,
            'question_text' => $item->question->question_text,
            'gave_up_at' => $item->gave_up_at?->toDateTimeString(),
        ]);
    }
}
