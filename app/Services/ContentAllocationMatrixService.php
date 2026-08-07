<?php

namespace App\Services;

use App\Models\Board;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\User;
use App\Models\Worksheet;

class ContentAllocationMatrixService
{
    /**
     * @return array{
     *     boards: list<array{id: int, code: string, name: string}>,
     *     board_id: int|null,
     *     uploaders: list<array{id: int, name: string, email: string}>,
     *     grades: list<array{id: int, name: string, sort_order: int}>,
     *     cells: array<string, array<string, array{count: int, statuses: array<string, int>}>>,
     *     drill: array<string, mixed>|null
     * }
     */
    public function build(?int $boardId, ?int $drillGradeId = null, ?int $drillUploaderId = null): array
    {
        $boards = Board::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Board $board) => $board->only(['id', 'code', 'name']))
            ->values()
            ->all();

        if ($boardId === null && $boards !== []) {
            $boardId = (int) ($boards[0]['id'] ?? 0) ?: null;
        }

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
            ->get(['id', 'name', 'sort_order'])
            ->map(fn (GradeLevel $grade) => $grade->only(['id', 'name', 'sort_order']))
            ->values()
            ->all();

        $tasks = ContentUploadTask::query()
            ->with([
                'assignee:id,name,email',
                'textbookChapter:id,textbook_id,syllabus_chapter_id,chapter_number,title,status,extraction_items,mcq_worksheet_id,mcq_worksheet_ids',
                'textbookChapter.textbook:id,grade_level_id,name,code',
                'textbookChapter.textbook.gradeLevel:id,name,sort_order',
                'textbookChapter.syllabusChapter:id,name,syllabus_version_id',
                'textbookChapter.syllabusChapter.syllabusVersion:id,board_id',
            ])
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->when($boardId, function ($query) use ($boardId) {
                $query->where(function ($inner) use ($boardId) {
                    $inner->whereHas(
                        'textbookChapter.syllabusChapter.syllabusVersion',
                        fn ($q) => $q->where('board_id', $boardId),
                    )->orWhereDoesntHave('textbookChapter.syllabusChapter');
                });
            })
            ->latest('id')
            ->get();

        $cells = [];
        foreach ($grades as $grade) {
            foreach ($uploaders as $uploader) {
                $cells[(string) $grade['id']][(string) $uploader['id']] = [
                    'count' => 0,
                    'statuses' => [],
                ];
            }
        }

        foreach ($tasks as $task) {
            $gradeId = $task->textbookChapter?->textbook?->grade_level_id;
            $uploaderId = $task->assigned_to_user_id;
            if (! $gradeId || ! $uploaderId) {
                continue;
            }

            $gKey = (string) $gradeId;
            $uKey = (string) $uploaderId;
            if (! isset($cells[$gKey][$uKey])) {
                $cells[$gKey][$uKey] = ['count' => 0, 'statuses' => []];
            }

            $cells[$gKey][$uKey]['count']++;
            $status = $task->status;
            $cells[$gKey][$uKey]['statuses'][$status] = ($cells[$gKey][$uKey]['statuses'][$status] ?? 0) + 1;
        }

        $drill = null;
        if ($drillGradeId && $drillUploaderId) {
            $drillTasks = $tasks
                ->filter(fn (ContentUploadTask $task) => (int) $task->textbookChapter?->textbook?->grade_level_id === $drillGradeId
                    && (int) $task->assigned_to_user_id === $drillUploaderId)
                ->values();

            $grade = collect($grades)->firstWhere('id', $drillGradeId);
            $uploader = collect($uploaders)->firstWhere('id', $drillUploaderId);

            $drill = [
                'grade' => $grade,
                'uploader' => $uploader,
                'chapters' => $drillTasks->map(fn (ContentUploadTask $task) => $this->serializeDrillRow($task))->all(),
            ];
        }

        return [
            'boards' => $boards,
            'board_id' => $boardId,
            'uploaders' => $uploaders,
            'grades' => $grades,
            'cells' => $cells,
            'drill' => $drill,
            'work_types' => [
                ['key' => 'content_upload', 'label' => 'Content upload (textbook MCQ)', 'active' => true],
                ['key' => 'mcq', 'label' => 'MCQ bank', 'active' => false],
                ['key' => 'fill_blank', 'label' => 'Fill in blanks', 'active' => false],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDrillRow(ContentUploadTask $task): array
    {
        $chapter = $task->textbookChapter;
        $questionCount = $this->questionCountForChapter($chapter);

        return [
            'id' => $task->id,
            'status' => $task->status,
            'status_label' => $this->shortStatusLabel($task->status, $questionCount),
            'status_group' => $this->statusGroup($task->status),
            'offered_amount_inr' => $task->offered_amount_inr,
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'question_count' => $questionCount,
            'chapter' => [
                'id' => $chapter?->id,
                'chapter_number' => $chapter?->chapter_number,
                'title' => $chapter?->title,
                'textbook_name' => $chapter?->textbook?->name,
                'textbook_code' => $chapter?->textbook?->code,
                'grade_name' => $chapter?->textbook?->gradeLevel?->name,
            ],
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
}
