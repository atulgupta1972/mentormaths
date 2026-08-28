<?php

namespace App\Services;

use App\Models\ContentQuestionDeleteRequest;
use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;
use App\Models\User;
use Illuminate\Support\Collection;

class ContentUploaderChapterLibraryService
{
    public function __construct(
        private ContentTextbookAccessService $access,
        private TextbookChapterMcqImportService $mcqImport,
    ) {}

    /**
     * @return array{
     *     grades: list<array<string, mixed>>,
     *     selected_grade_id: int|null,
     *     chapters: list<array<string, mixed>>
     * }
     */
    public function index(User $user, ?int $gradeLevelId = null): array
    {
        $tasks = ContentUploadTask::query()
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->with(['textbookChapter.textbook.gradeLevel'])
            ->latest()
            ->get();

        $grades = $tasks
            ->map(function (ContentUploadTask $task) {
                $grade = $task->textbookChapter?->textbook?->gradeLevel;

                return $grade ? [
                    'id' => $grade->id,
                    'name' => $grade->name,
                ] : null;
            })
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();

        $selectedGradeId = $gradeLevelId
            ?: ($grades[0]['id'] ?? null);

        $chapters = $tasks
            ->filter(function (ContentUploadTask $task) use ($selectedGradeId) {
                if (! $task->textbookChapter) {
                    return false;
                }

                if ($selectedGradeId === null) {
                    return true;
                }

                return (int) $task->textbookChapter->textbook?->grade_level_id === (int) $selectedGradeId;
            })
            ->map(fn (ContentUploadTask $task) => $this->serializeChapterCard($task))
            ->values()
            ->all();

        return [
            'grades' => $grades,
            'selected_grade_id' => $selectedGradeId,
            'chapters' => $chapters,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, TextbookChapter $chapter): array
    {
        $task = $this->access->assignedTask($user, $chapter);
        if (! $task) {
            abort(403, 'You are not assigned to this chapter.');
        }

        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter']);
        $items = $this->mcqImport->itemsWithDiagramPreviewUrls($chapter->extraction_items ?? []);
        $pendingByIndex = ContentQuestionDeleteRequest::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('status', ContentQuestionDeleteRequest::STATUS_PENDING)
            ->get()
            ->keyBy('item_index');

        $questions = [];
        foreach ($items as $index => $item) {
            $pending = $pendingByIndex->get($index);
            $options = collect($item['mcq_options'] ?? [])
                ->map(fn (array $option, int $optionIndex) => [
                    'text' => trim((string) ($option['text'] ?? '')),
                    'is_correct' => (bool) ($option['is_correct'] ?? false),
                    'letter' => chr(65 + $optionIndex),
                ])
                ->values()
                ->all();

            $questions[] = [
                'index' => $index,
                'number' => $index + 1,
                'label' => $item['label'] ?? ('Q'.($index + 1)),
                'topic' => $item['topic'] ?? null,
                'question_text' => $item['question_text'] ?? '',
                'options' => $options,
                'correct_answer' => $item['correct_answer'] ?? null,
                'explanation' => $item['explanation'] ?? null,
                'diagram_preview_url' => $item['diagram_preview_url'] ?? null,
                'fill_blank' => filled($item['fill_blank_question_text'] ?? null) && ! ($item['fill_blank_skipped'] ?? false)
                    ? [
                        'question_text' => $item['fill_blank_question_text'] ?? '',
                        'correct_answer' => $item['fill_blank_correct_answer'] ?? '',
                        'answer_format' => $item['fill_blank_answer_format'] ?? null,
                        'checked' => filled($item['fill_blank_checked_at'] ?? null),
                        'skipped' => (bool) ($item['fill_blank_skipped'] ?? false),
                    ]
                    : null,
                'delete_request' => $pending ? [
                    'id' => $pending->id,
                    'status' => $pending->status,
                    'reason' => $pending->reason,
                ] : null,
            ];
        }

        return [
            'chapter' => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'status' => $chapter->status,
                'status_label' => $chapter->statusLabel(),
                'textbook_name' => $chapter->textbook?->name,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
                'grade_id' => $chapter->textbook?->grade_level_id,
                'question_count' => count($questions),
            ],
            'task' => [
                'id' => $task->id,
                'status' => $task->status,
                'status_label' => $task->statusLabel(),
                'can_delete' => ! $task->isLockedForUploaderDelete(),
                'can_add' => true,
            ],
            'questions' => $questions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeChapterCard(ContentUploadTask $task): array
    {
        $chapter = $task->textbookChapter;

        return [
            'id' => $chapter?->id,
            'chapter_number' => $chapter?->chapter_number,
            'title' => $chapter?->title,
            'textbook_name' => $chapter?->textbook?->name,
            'grade_name' => $chapter?->textbook?->gradeLevel?->name,
            'question_count' => count($chapter?->extraction_items ?? []),
            'chapter_status' => $chapter?->status,
            'task_status' => $task->status,
            'task_status_label' => $task->statusLabel(),
            'can_delete' => ! $task->isLockedForUploaderDelete(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingDeleteRequestsForTask(ContentUploadTask $task): Collection
    {
        return $task->questionDeleteRequests()
            ->with('requester:id,name')
            ->latest()
            ->get()
            ->map(fn (ContentQuestionDeleteRequest $request) => [
                'id' => $request->id,
                'item_index' => $request->item_index,
                'question_id' => $request->question_id,
                'question_text' => $request->question_text,
                'reason' => $request->reason,
                'status' => $request->status,
                'requester_name' => $request->requester?->name,
                'created_at' => $request->created_at?->toDateTimeString(),
                'admin_note' => $request->admin_note,
            ]);
    }
}
