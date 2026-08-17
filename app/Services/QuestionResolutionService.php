<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\Question;
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
        private PracticeCorrectionQueueService $correctionQueue,
        private ContentUploadTaskService $contentUploadTasks,
    ) {}
    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForEnrollment(int $enrollmentId, bool $forAdmin = false): array
    {
        $items = QuestionResolutionItem::query()
            ->with([
                'question.options',
                'question.worksheets:id,set_code,set_number',
                'assignment.practiceSet:id,set_code,set_number',
            ])
            ->where('student_enrollment_id', $enrollmentId)
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->orderByDesc('gave_up_at')
            ->get()
            ->filter(fn (QuestionResolutionItem $item) => $item->question !== null)
            ->values();

        $uploaderByQuestion = $forAdmin
            ? $this->contentUploadTasks->tasksKeyedByQuestionId($items->pluck('question_id')->all())
            : collect();

        return $items
            ->map(function (QuestionResolutionItem $item) use ($forAdmin, $uploaderByQuestion) {
                if (! $forAdmin) {
                    return $this->formatItem($item);
                }

                return $this->formatItem($item, $uploaderByQuestion->get((int) $item->question_id));
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function formatItem(QuestionResolutionItem $item, ?ContentUploadTask $uploadTask = null): array
    {
        $item->loadMissing(['question.options', 'question.blankAnswer', 'question.worksheets', 'assignment.practiceSet']);

        $payload = [
            'id' => $item->id,
            'question_id' => $item->question_id,
            'question_type' => $item->question->type,
            'answer_format' => $item->question->blankAnswer?->answer_format,
            'answer_format_label' => $this->answerValidation->formatLabel($item->question->blankAnswer?->answer_format),
            'gave_up_at' => $item->gave_up_at?->toDateTimeString(),
            ...$this->adminSetLinks($item),
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

        if ($uploadTask !== null || func_num_args() > 1) {
            $payload = array_merge($payload, $this->contentUploadTasks->uploaderReturnPayload($uploadTask));
        }

        return $payload;
    }

    /**
     * Queue a sum for teacher help when the student is stuck outside guided practice.
     */
    public function queueHelpRequest(Student $student, Question $question, ?int $setAssignmentId = null): void
    {
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            throw new \InvalidArgumentException('No active enrollment found.');
        }

        $existing = QuestionResolutionItem::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('question_id', $question->id)
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->first();

        if ($existing) {
            $payload = ['gave_up_at' => now()];

            if ($setAssignmentId && ! $existing->set_assignment_id) {
                $payload['set_assignment_id'] = $setAssignmentId;
            }

            $existing->update($payload);

            return;
        }

        QuestionResolutionItem::create([
            'student_enrollment_id' => $enrollment->id,
            'question_id' => $question->id,
            'set_assignment_id' => $setAssignmentId,
            'status' => QuestionResolutionItem::STATUS_PENDING,
            'gave_up_at' => now(),
        ]);
    }

    /**
     * @return array{correct: bool, message: string, resolved: bool}
     */
    public function submitAnswer(QuestionResolutionItem $item, ?int $optionId = null, ?string $answerText = null): array
    {
        if ($item->status !== QuestionResolutionItem::STATUS_PENDING) {
            throw new \InvalidArgumentException('This question is already resolved.');
        }

        $item->loadMissing(['question.options', 'question.blankAnswer', 'enrollment.student']);

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

        $student = $item->enrollment?->student;

        if ($student && $item->question) {
            $this->correctionQueue->flagNeedsRevisionAfterTeacherHelp($student, $item->question);
        }

        return [
            'correct' => true,
            'resolved' => true,
            'message' => 'Well done — this sum is cleared from your help list. It will come back in daily drill so you can show you can do it on your own.',
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
                'question:id,question_text,type',
                'question.worksheets:id,set_code,set_number',
                'enrollment.student:id,name',
                'enrollment.gradeLevel:id,name',
                'assignment.practiceSet:id,set_code,set_number',
            ])
            ->where('status', QuestionResolutionItem::STATUS_PENDING)
            ->whereHas('enrollment', function ($q) use ($studentIds, $academicYearId) {
                $q->whereIn('student_id', $studentIds);

                if ($academicYearId) {
                    $q->where('academic_year_id', $academicYearId);
                }
            });

        $items = $query->orderByDesc('gave_up_at')->get()
            ->filter(fn (QuestionResolutionItem $item) => $item->enrollment && $item->question)
            ->values();

        $uploaderByQuestion = $this->contentUploadTasks->tasksKeyedByQuestionId($items->pluck('question_id')->all());

        return $items->map(function (QuestionResolutionItem $item) use ($uploaderByQuestion) {
            return [
                'id' => $item->id,
                'student_id' => $item->enrollment->student_id,
                'student_name' => $item->enrollment->student?->name,
                'class_name' => $item->enrollment->gradeLevel?->name,
                ...$this->adminSetLinks($item),
                'question_text' => mb_convert_encoding((string) $item->question->question_text, 'UTF-8', 'UTF-8'),
                'gave_up_at' => $item->gave_up_at?->toDateTimeString(),
                ...$this->contentUploadTasks->uploaderReturnPayload(
                    $uploaderByQuestion->get((int) $item->question_id),
                ),
            ];
        });
    }

    /**
     * @return array{
     *     question_id: ?int,
     *     question_type: ?string,
     *     set_code: ?string,
     *     set_number: mixed,
     *     worksheet_id: ?int,
     *     set_url: ?string,
     *     edit_url: ?string
     * }
     */
    private function adminSetLinks(QuestionResolutionItem $item): array
    {
        $item->loadMissing(['question.worksheets', 'assignment.practiceSet']);

        $question = $item->question;
        $worksheet = $item->assignment?->practiceSet
            ?? $question?->worksheets->first();

        $setCode = $worksheet?->set_code;
        $worksheetId = $worksheet?->id;

        $setUrl = null;
        if ($worksheetId) {
            $setUrl = route('admin.questions.sets.show', $worksheetId);
        } elseif (filled($setCode)) {
            $setUrl = route('admin.questions.set-code', ['code' => $setCode]);
        }

        $editUrl = null;
        if ($question) {
            $editUrl = $question->isFillInBlank() && filled($setCode)
                ? route('admin.questions.set-code', ['code' => $setCode]).'#question-'.$question->id
                : route('admin.questions.edit', $question);
        }

        return [
            'question_id' => $item->question_id,
            'question_type' => $question?->type,
            'set_code' => $setCode,
            'set_number' => $worksheet?->set_number,
            'worksheet_id' => $worksheetId,
            'set_url' => $setUrl,
            'edit_url' => $editUrl,
        ];
    }
}
