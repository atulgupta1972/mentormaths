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
        private ContentVerificationService $verificationService,
    ) {}

    /**
     * @return array{
     *     grades: list<array<string, mixed>>,
     *     selected_grade_id: int|null,
     *     chapters: list<array<string, mixed>>,
     *     gemini_pending_count: int,
     *     gemini_blocked: bool,
     *     gemini_pending: list<array<string, mixed>>
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

        $progressByTask = $this->verificationService->progressForTasks($tasks, $user);

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
            ->map(fn (ContentUploadTask $task) => $this->serializeChapterCard(
                $task,
                $progressByTask[(int) $task->id] ?? null,
            ))
            ->values()
            ->all();

        $geminiPending = $this->geminiPendingFromTasks($tasks, $progressByTask);

        return [
            'grades' => $grades,
            'selected_grade_id' => $selectedGradeId,
            'chapters' => $chapters,
            'gemini_pending_count' => $geminiPending->count(),
            'gemini_blocked' => $geminiPending->isNotEmpty(),
            'gemini_pending' => $geminiPending->all(),
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

        $allTasks = ContentUploadTask::query()
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->with(['textbookChapter.textbook.gradeLevel'])
            ->latest()
            ->get();
        $progressByTask = $this->verificationService->progressForTasks($allTasks, $user);
        $thisProgress = $progressByTask[(int) $task->id] ?? null;
        $geminiPending = $this->geminiPendingFromTasks($allTasks, $progressByTask);
        $thisChapterNeedsGemini = $this->needsGeminiCheck($thisProgress);

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
                'can_add' => $geminiPending->isEmpty(),
                'needs_gemini_check' => $thisChapterNeedsGemini,
                'gemini_progress' => $thisProgress,
            ],
            'questions' => $questions,
            'gemini_pending_count' => $geminiPending->count(),
            'gemini_blocked' => $geminiPending->isNotEmpty(),
            'gemini_pending' => $geminiPending->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $geminiProgress
     * @return array<string, mixed>
     */
    private function serializeChapterCard(ContentUploadTask $task, ?array $geminiProgress = null): array
    {
        $chapter = $task->textbookChapter;

        return [
            'id' => $chapter?->id,
            'task_id' => $task->id,
            'chapter_number' => $chapter?->chapter_number,
            'title' => $chapter?->title,
            'textbook_name' => $chapter?->textbook?->name,
            'grade_name' => $chapter?->textbook?->gradeLevel?->name,
            'question_count' => count($chapter?->extraction_items ?? []),
            'chapter_status' => $chapter?->status,
            'task_status' => $task->status,
            'task_status_label' => $task->statusLabel(),
            'can_delete' => ! $task->isLockedForUploaderDelete(),
            'gemini_progress' => $geminiProgress,
            'needs_gemini_check' => $this->needsGeminiCheck($geminiProgress),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ContentUploadTask>  $tasks
     * @param  array<int, array<string, mixed>>  $progressByTask
     * @return Collection<int, array<string, mixed>>
     */
    private function geminiPendingFromTasks($tasks, array $progressByTask): Collection
    {
        return $tasks
            ->filter(fn (ContentUploadTask $task) => $this->needsGeminiCheck($progressByTask[(int) $task->id] ?? null))
            ->map(function (ContentUploadTask $task) use ($progressByTask) {
                $chapter = $task->textbookChapter;
                $progress = $progressByTask[(int) $task->id] ?? null;

                return [
                    'id' => $task->id,
                    'chapter_id' => $chapter?->id,
                    'chapter_label' => $chapter
                        ? trim(
                            ($chapter->textbook?->gradeLevel?->name ? $chapter->textbook->gradeLevel->name.' · ' : '')
                            .($chapter->textbook?->name ? $chapter->textbook->name.' · ' : '')
                            .'Ch '.$chapter->displayChapterNumber().' — '.$chapter->displayTitle(),
                        )
                        : 'Chapter',
                    'gemini_progress' => $progress,
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>|null  $progress
     */
    private function needsGeminiCheck(?array $progress): bool
    {
        return (bool) ($progress['can_gemini'] ?? false)
            && (int) ($progress['pending'] ?? 0) > 0;
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
