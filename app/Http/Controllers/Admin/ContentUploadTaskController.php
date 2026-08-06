<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ContentUploadTask;
use App\Models\GradeLevel;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\User;
use App\Services\AdminGradeContext;
use App\Services\ContentRateCardService;
use App\Services\ContentUploadTaskService;
use App\Services\ContentWorkSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentUploadTaskController extends Controller
{
    public function __construct(
        private ContentUploadTaskService $taskService,
        private ContentWorkSessionService $sessionService,
        private AdminGradeContext $gradeContext,
        private ContentRateCardService $rateCardService,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $tasks = ContentUploadTask::query()
            ->with([
                'assignee:id,name,email',
                'textbookChapter:id,textbook_id,chapter_number,title,status',
                'textbookChapter.textbook:id,grade_level_id,name',
                'textbookChapter.textbook.gradeLevel:id,name',
            ])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ContentUploadTask $task) => $this->serializeTask($task));

        return Inertia::render('Admin/ContentTasks/Index', [
            'tasks' => $tasks,
            'filters' => ['status' => $status],
            'statuses' => $this->statusOptions(),
            'pendingPublishCount' => ContentUploadTask::query()
                ->where('status', ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH)
                ->count(),
        ]);
    }

    public function create(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $uploaders = User::query()
            ->whereHas('groups', fn ($q) => $q->where('code', User::ROLE_CONTENT_UPLOADER))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $textbooks = [];
        $syllabusChapters = [];

        if ($gradeLevel) {
            $textbooks = Textbook::query()
                ->where('grade_level_id', $gradeLevel->id)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Textbook $book) => [
                    'id' => $book->id,
                    'name' => $book->name,
                    'code' => $book->code,
                    'label' => "{$book->name} ({$book->code})",
                ])
                ->values()
                ->all();

            $activeYear = AcademicYear::active();
            if ($activeYear) {
                $syllabus = SyllabusVersion::query()
                    ->with(['chapters' => fn ($q) => $q->orderBy('sort_order')])
                    ->where('academic_year_id', $activeYear->id)
                    ->where('grade_level_id', $gradeLevel->id)
                    ->first();

                $assignedSyllabusIds = ContentUploadTask::query()
                    ->whereHas('textbookChapter.textbook', fn ($q) => $q->where('grade_level_id', $gradeLevel->id))
                    ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
                    ->with('textbookChapter:id,syllabus_chapter_id')
                    ->get()
                    ->pluck('textbookChapter.syllabus_chapter_id')
                    ->filter()
                    ->unique()
                    ->all();

                $syllabusChapters = $syllabus?->chapters
                    ->map(function (SyllabusChapter $chapter) use ($assignedSyllabusIds, $gradeLevel) {
                        return [
                            'id' => $chapter->id,
                            'chapter_number' => $chapter->chapter_number,
                            'name' => $chapter->name,
                            'label' => self::chapterLabel($chapter),
                            'default_amount_inr' => $this->rateCardService->resolveAmountForSyllabusChapter(
                                $gradeLevel->id,
                                $chapter,
                            ),
                            'has_task' => in_array($chapter->id, $assignedSyllabusIds, true),
                        ];
                    })
                    ->values()
                    ->all() ?? [];
            }
        }

        return Inertia::render('Admin/ContentTasks/Create', [
            'uploaders' => $uploaders,
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'textbooks' => $textbooks,
            'syllabusChapters' => $syllabusChapters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        abort_unless($gradeLevel, 422, 'Select a class from the top bar first.');

        $validated = $request->validate([
            'assigned_to_user_id' => ['required', 'exists:users,id'],
            'textbook_id' => ['nullable', 'integer', Rule::exists('textbooks', 'id')],
            'book_name' => ['required_without:textbook_id', 'nullable', 'string', 'max:255'],
            'book_code' => ['required_without:textbook_id', 'nullable', 'string', 'max:32', 'alpha_dash'],
            'syllabus_chapter_ids' => ['required', 'array', 'min:1'],
            'syllabus_chapter_ids.*' => ['integer', 'exists:syllabus_chapters,id'],
            'offered_amount_inr' => ['nullable', 'integer', 'min:100', 'max:500000'],
            'duplicate_override_reason' => ['nullable', 'string', 'max:2000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $uploader = User::query()->findOrFail($validated['assigned_to_user_id']);

        if (! $uploader->isContentUploader()) {
            return back()->with('error', 'Selected user is not a content uploader.');
        }

        if (! empty($validated['textbook_id'])) {
            $textbook = Textbook::query()->findOrFail($validated['textbook_id']);
            if ($textbook->grade_level_id !== $gradeLevel->id) {
                return back()->with('error', 'Selected textbook does not belong to the current class.');
            }
        } else {
            $textbook = Textbook::query()->firstOrCreate(
                [
                    'grade_level_id' => $gradeLevel->id,
                    'code' => strtolower($validated['book_code']),
                ],
                [
                    'name' => $validated['book_name'],
                    'created_by' => $request->user()->id,
                ],
            );

            if ($textbook->name !== $validated['book_name']) {
                $textbook->update(['name' => $validated['book_name']]);
            }
        }

        try {
            $amount = isset($validated['offered_amount_inr'])
                ? (int) $validated['offered_amount_inr']
                : null;

            $this->taskService->assignSyllabusChapters(
                $textbook,
                $validated['syllabus_chapter_ids'],
                $uploader,
                $request->user(),
                $amount,
                $validated['duplicate_override_reason'] ?? null,
                $validated['admin_notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.content-tasks.index')
            ->with('success', 'Chapter assignment(s) created. Uploader emailed to review and agree before starting.');
    }

    public function show(ContentUploadTask $contentTask): Response
    {
        $contentTask->load([
            'assignee:id,name,email',
            'assigner:id,name',
            'textbookChapter.textbook.gradeLevel',
        ]);

        return Inertia::render('Admin/ContentTasks/Show', [
            'task' => $this->serializeTask($contentTask, detailed: true),
            'activeSeconds' => $this->sessionService->totalActiveSeconds($contentTask),
        ]);
    }

    public function publish(ContentUploadTask $contentTask, Request $request): RedirectResponse
    {
        try {
            $this->taskService->publish($contentTask, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Task marked published. Use textbook chapter publish for live content.');
    }

    private static function chapterLabel(SyllabusChapter $chapter): string
    {
        $number = trim((string) $chapter->chapter_number);

        return $number !== '' ? "{$number} — {$chapter->name}" : $chapter->name;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTask(ContentUploadTask $task, bool $detailed = false): array
    {
        $chapter = $task->textbookChapter;

        $data = [
            'id' => $task->id,
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'offered_amount_inr' => $task->offered_amount_inr,
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'agreed_at' => $task->agreed_at?->toIso8601String(),
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'published_at' => $task->published_at?->toIso8601String(),
            'assignee' => $task->assignee?->only(['id', 'name', 'email']),
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'status' => $chapter->status,
                'textbook_name' => $chapter->textbook?->name,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
            ] : null,
        ];

        if ($detailed) {
            $data['duplicate_override_reason'] = $task->duplicate_override_reason;
            $data['admin_notes'] = $task->admin_notes;
            $data['assigner'] = $task->assigner?->only(['id', 'name']);
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private function statusOptions(): array
    {
        return [
            ContentUploadTask::STATUS_PENDING_AGREEMENT,
            ContentUploadTask::STATUS_IN_PROGRESS,
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
            ContentUploadTask::STATUS_CANCELLED,
        ];
    }
}
