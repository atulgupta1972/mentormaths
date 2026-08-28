<?php

namespace App\Services;

use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\SyllabusChapter;
use App\Models\User;
use App\Models\Worksheet;

class ContentAllocationMatrixService
{
    public function __construct(
        private TextbookChapterBookService $bookService,
        private ContentVerificationService $verificationService,
    ) {}

    /**
     * @return array{
     *     boards: list<array{id: int, code: string, name: string}>,
     *     board_id: int|null,
     *     uploaders: list<array{id: int, name: string, email: string}>,
     *     grades: list<array{id: int, name: string, sort_order: int}>,
     *     cells: array<string, array<string, array{count: int, statuses: array<string, int>}>>,
     *     drill: array<string, mixed>|null,
     *     total_assignments: int,
     *     database_total: int
     * }
     */
    public function build(?int $boardId, ?int $drillGradeId = null, ?int $drillUploaderId = null, ?User $progressUser = null): array
    {
        $this->bookService->mergeAllDuplicateBookChapters();

        $boards = Board::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Board $board) => $board->only(['id', 'code', 'name']))
            ->values()
            ->all();

        $uploaders = User::query()
            ->whereHas('groups', fn ($q) => $q->where('code', User::ROLE_CONTENT_UPLOADER))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => $user->only(['id', 'name', 'email']))
            ->values()
            ->all();

        $grades = GradeLevel::query()
            ->where('is_active', true)
            ->whereBetween('sort_order', [4, 12])
            ->orderBy('sort_order')
            ->get(['id', 'name', 'sort_order']);

        $databaseTotal = ContentUploadTask::query()
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->count();

        // Always load all active assignments — board filter is display-only for grades,
        // not a hard filter that can zero out the matrix when board IDs differ.
        $tasks = ContentUploadTask::query()
            ->with([
                'assignee:id,name,email',
                'textbookChapter:id,textbook_id,syllabus_chapter_id,chapter_number,title,status,extraction_items,mcq_worksheet_id,mcq_worksheet_ids',
                'textbookChapter.textbook:id,grade_level_id,name,code',
                'textbookChapter.textbook.gradeLevel:id,name,sort_order',
                'textbookChapter.syllabusChapter:id,name,chapter_number,chapter_head_id,syllabus_version_id',
                'textbookChapter.syllabusChapter.chapterHead:id,name',
                'textbookChapter.syllabusChapter.syllabusVersion:id,board_id,grade_level_id',
                'textbookChapter.syllabusChapter.syllabusVersion.board:id,code,name',
            ])
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->latest('id')
            ->get();

        $boardCode = null;
        if ($boardId) {
            $boardCode = collect($boards)->firstWhere('id', $boardId)['code']
                ?? Board::query()->whereKey($boardId)->value('code');
        }

        if ($boardCode) {
            $filtered = $tasks->filter(function (ContentUploadTask $task) use ($boardCode) {
                return $this->taskMatchesBoardCode($task, (string) $boardCode);
            })->values();

            // If filter would hide everything but DB has work, keep all (avoid blank matrix).
            if ($filtered->isNotEmpty() || $tasks->isEmpty()) {
                $tasks = $filtered;
            }
        }

        $gradeIdsFromTasks = $tasks
            ->map(fn (ContentUploadTask $task) => $this->resolveGradeId($task))
            ->filter()
            ->unique()
            ->values();

        $missingGradeIds = $gradeIdsFromTasks
            ->reject(fn ($id) => $grades->contains('id', (int) $id))
            ->all();

        if ($missingGradeIds !== []) {
            $extraGrades = GradeLevel::query()
                ->whereIn('id', $missingGradeIds)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'sort_order']);
            $grades = $grades->concat($extraGrades)->sortBy('sort_order')->values();
        }

        $gradeRows = $grades
            ->map(fn (GradeLevel $grade) => $grade->only(['id', 'name', 'sort_order']))
            ->values()
            ->all();

        $uploaderIds = collect($uploaders)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Include assignees even if they lost the content_uploader group later.
        $assigneeIds = $tasks->pluck('assigned_to_user_id')->map(fn ($id) => (int) $id)->unique()->all();
        $missingUploaderIds = array_values(array_diff($assigneeIds, $uploaderIds));
        if ($missingUploaderIds !== []) {
            $extraUploaders = User::query()
                ->whereIn('id', $missingUploaderIds)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user) => $user->only(['id', 'name', 'email']))
                ->all();
            $uploaders = array_values(array_merge($uploaders, $extraUploaders));
        }

        $emptyCell = fn () => [
            'count' => 0,
            'statuses' => [],
            'breakup' => [
                'under_review' => 0,
                'submitted' => 0,
                'published' => 0,
                'gemini_pending' => 0,
                'gemini_done' => 0,
            ],
        ];

        $progressByTaskId = $progressUser
            ? $this->verificationService->progressForTasks($tasks, $progressUser)
            : [];

        $cells = [];
        foreach ($gradeRows as $grade) {
            foreach ($uploaders as $uploader) {
                $cells[(string) $grade['id']][(string) $uploader['id']] = $emptyCell();
            }
        }

        foreach ($tasks as $task) {
            $gradeId = $this->resolveGradeId($task);
            $uploaderId = $task->assigned_to_user_id;
            if (! $gradeId || ! $uploaderId) {
                continue;
            }

            $gKey = (string) $gradeId;
            $uKey = (string) $uploaderId;
            if (! isset($cells[$gKey][$uKey])) {
                $cells[$gKey][$uKey] = $emptyCell();
            }

            $cells[$gKey][$uKey]['count']++;
            $status = $task->status;
            $cells[$gKey][$uKey]['statuses'][$status] = ($cells[$gKey][$uKey]['statuses'][$status] ?? 0) + 1;
            $bucket = $this->breakupBucket($status);
            $cells[$gKey][$uKey]['breakup'][$bucket] = ($cells[$gKey][$uKey]['breakup'][$bucket] ?? 0) + 1;

            if ($task->status === ContentUploadTask::STATUS_PUBLISHED) {
                $progress = $progressByTaskId[(int) $task->id] ?? null;
                if ($progress && ($progress['can_gemini'] ?? false) && (int) ($progress['total'] ?? 0) > 0) {
                    if ((int) ($progress['pending'] ?? 0) > 0) {
                        $cells[$gKey][$uKey]['breakup']['gemini_pending']++;
                    } else {
                        $cells[$gKey][$uKey]['breakup']['gemini_done']++;
                    }
                }
            }
        }

        $uploaders = array_values(array_filter($uploaders, function (array $uploader) use ($cells) {
            foreach ($cells as $gradeCells) {
                if (($gradeCells[(string) $uploader['id']]['count'] ?? 0) > 0) {
                    return true;
                }
            }

            return false;
        }));

        $gradeRows = array_values(array_filter($gradeRows, function (array $grade) use ($cells, $uploaders) {
            $gradeCells = $cells[(string) $grade['id']] ?? [];
            foreach ($uploaders as $uploader) {
                if (($gradeCells[(string) $uploader['id']]['count'] ?? 0) > 0) {
                    return true;
                }
            }

            return false;
        }));

        $drill = null;
        if ($drillGradeId && $drillUploaderId) {
            $drillTasks = $tasks
                ->filter(fn (ContentUploadTask $task) => (int) $this->resolveGradeId($task) === $drillGradeId
                    && (int) $task->assigned_to_user_id === $drillUploaderId)
                ->values();

            $grade = collect($gradeRows)->firstWhere('id', $drillGradeId);
            $uploader = collect($uploaders)->firstWhere('id', $drillUploaderId);

            $progressByTask = $progressUser
                ? $this->verificationService->progressForTasks($drillTasks, $progressUser)
                : [];

            $chapters = $drillTasks
                ->map(fn (ContentUploadTask $task) => $this->serializeDrillRow(
                    $task,
                    $progressByTask[(int) $task->id] ?? null,
                ))
                ->sortBy(function (array $row) {
                    $book = mb_strtolower((string) ($row['chapter']['textbook_name'] ?? ''));
                    $number = (string) ($row['chapter']['chapter_number'] ?? '');

                    return $book.'|'.SyllabusChapter::orderKey($number);
                })
                ->values();

            $drill = [
                'grade' => $grade,
                'uploader' => $uploader,
                'chapters' => $chapters->all(),
                'breakup' => [
                    'under_review' => $chapters->where('breakup_bucket', 'under_review')->count(),
                    'submitted' => $chapters->where('breakup_bucket', 'submitted')->count(),
                    'published' => $chapters->where('breakup_bucket', 'published')->count(),
                ],
                'gemini' => [
                    'done' => $chapters->filter(fn (array $row) => ($row['gemini_progress']['can_gemini'] ?? false)
                        && (int) ($row['gemini_progress']['pending'] ?? 0) === 0
                        && (int) ($row['gemini_progress']['total'] ?? 0) > 0)->count(),
                    'pending' => $chapters->filter(fn (array $row) => ($row['gemini_progress']['can_gemini'] ?? false)
                        && (int) ($row['gemini_progress']['pending'] ?? 0) > 0)->count(),
                ],
            ];
        }

        return [
            'boards' => $boards,
            'board_id' => $boardId,
            'uploaders' => $uploaders,
            'grades' => $gradeRows,
            'cells' => $cells,
            'drill' => $drill,
            'total_assignments' => $tasks->count(),
            'database_total' => $databaseTotal,
            'work_types' => [
                ['key' => 'content_upload', 'label' => 'Content upload (textbook MCQ)', 'active' => true],
                ['key' => 'mcq', 'label' => 'MCQ bank', 'active' => false],
                ['key' => 'fill_blank', 'label' => 'Fill in blanks', 'active' => false],
            ],
        ];
    }

    private function taskMatchesBoardCode(ContentUploadTask $task, string $boardCode): bool
    {
        $taskBoardCode = $task->textbookChapter?->syllabusChapter?->syllabusVersion?->board?->code;

        if ($taskBoardCode === null || $taskBoardCode === '') {
            return true;
        }

        return strcasecmp($taskBoardCode, $boardCode) === 0;
    }

    private function resolveGradeId(ContentUploadTask $task): ?int
    {
        $fromTextbook = $task->textbookChapter?->textbook?->grade_level_id;
        if ($fromTextbook) {
            return (int) $fromTextbook;
        }

        $fromSyllabus = $task->textbookChapter?->syllabusChapter?->syllabusVersion?->grade_level_id;

        return $fromSyllabus ? (int) $fromSyllabus : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDrillRow(ContentUploadTask $task, ?array $geminiProgress = null): array
    {
        $chapter = $task->textbookChapter;
        $questionCount = $this->questionCountForChapter($chapter);

        $breakupBucket = $this->breakupBucket($task->status);

        return [
            'id' => $task->id,
            'status' => $task->status,
            'work_type' => $task->work_type ?: ContentUploadTask::WORK_TYPE_MCQ_UPLOAD,
            'work_type_label' => $task->workTypeLabel(),
            'status_label' => $this->shortStatusLabel($task->status, $questionCount),
            'status_group' => $this->statusGroup($task->status),
            'breakup_bucket' => $breakupBucket,
            'can_review_and_publish' => $breakupBucket === 'submitted',
            'can_reassign' => $task->canReassign(),
            'can_gemini_verify' => (bool) ($geminiProgress['can_gemini'] ?? false),
            'gemini_progress' => $geminiProgress,
            'offered_amount_inr' => $task->offered_amount_inr,
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'question_count' => $questionCount,
            'chapter' => [
                'id' => $chapter?->id,
                'chapter_number' => $chapter?->displayChapterNumber(),
                'title' => $chapter?->displayTitle(),
                'chapter_head_name' => $chapter?->displayChapterHeadName(),
                'textbook_name' => $chapter?->textbook?->name,
                'textbook_code' => $chapter?->textbook?->code,
                'book_author_name' => $chapter?->textbook?->name,
                'board_code' => $chapter?->syllabusChapter?->syllabusVersion?->board?->code,
                'board_name' => $chapter?->syllabusChapter?->syllabusVersion?->board?->name,
                'grade_name' => $chapter?->textbook?->gradeLevel?->name,
            ],
            'rate_description' => $task->rateDescription(),
        ];
    }

    private function questionCountForChapter($chapter): int
    {
        if (! $chapter) {
            return 0;
        }

        $items = $chapter->extraction_items ?? [];
        if (is_array($items) && $items !== []) {
            return count($items);
        }

        $worksheetIds = $chapter->mcqWorksheetIds();
        if ($worksheetIds === []) {
            return 0;
        }

        return (int) Worksheet::query()
            ->whereIn('id', $worksheetIds)
            ->withCount('questions')
            ->get()
            ->sum('questions_count');
    }

    private function shortStatusLabel(string $status, int $questionCount): string
    {
        $countSuffix = $questionCount > 0 ? " ({$questionCount})" : '';

        return match ($status) {
            ContentUploadTask::STATUS_PENDING_AGREEMENT => 'Awaiting agreement',
            ContentUploadTask::STATUS_IN_PROGRESS => 'Under upload',
            ContentUploadTask::STATUS_UPLOADED => 'Uploaded'.$countSuffix,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS => 'Verifying'.$countSuffix,
            ContentUploadTask::STATUS_VERIFIED => 'Verified'.$countSuffix,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH => 'Submitted'.$countSuffix,
            ContentUploadTask::STATUS_PUBLISHED => 'Published'.$countSuffix,
            ContentUploadTask::STATUS_CANCELLED => 'Cancelled',
            default => $status,
        };
    }

    private function statusGroup(string $status): string
    {
        return match ($status) {
            ContentUploadTask::STATUS_PENDING_AGREEMENT => 'awaiting',
            ContentUploadTask::STATUS_IN_PROGRESS => 'under_upload',
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED => 'uploaded',
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH => 'submitted',
            ContentUploadTask::STATUS_PUBLISHED => 'published',
            default => 'other',
        };
    }

    private function breakupBucket(string $status): string
    {
        return match ($status) {
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH => 'submitted',
            ContentUploadTask::STATUS_PUBLISHED => 'published',
            default => 'under_review',
        };
    }
}
