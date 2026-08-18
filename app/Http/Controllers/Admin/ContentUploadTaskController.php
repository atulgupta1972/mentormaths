<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentQuestionDeleteRequest;
use App\Models\ContentRateCard;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationRun;
use App\Models\QuestionResolutionItem;
use App\Models\SyllabusChapter;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Services\AdminGradeContext;
use App\Services\ClassAssignmentService;
use App\Services\ContentAllocationMatrixService;
use App\Services\ContentChapterQuestionService;
use App\Services\ContentDuplicateGuardService;
use App\Services\ContentRateCardService;
use App\Services\ContentUploaderChapterLibraryService;
use App\Services\ContentUploadTaskService;
use App\Services\ContentVerificationService;
use App\Services\ContentWorkSessionService;
use App\Services\TextbookMcqSetPlanService;
use App\Services\TextbookChapterBookService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentUploadTaskController extends Controller
{
    public function __construct(
        private ContentUploadTaskService $taskService,
        private ContentVerificationService $verificationService,
        private ContentWorkSessionService $sessionService,
        private AdminGradeContext $gradeContext,
        private ClassAssignmentService $classAssignment,
        private ContentRateCardService $rateCardService,
        private ContentAllocationMatrixService $matrixService,
        private TextbookMcqSetPlanService $setPlanService,
        private ContentChapterQuestionService $chapterQuestions,
        private ContentUploaderChapterLibraryService $chapterLibrary,
        private TextbookChapterBookService $bookService,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $boardId = $request->filled('board_id') ? $request->integer('board_id') : null;
        $drillGradeId = $request->integer('drill_grade_id') ?: null;
        $drillUploaderId = $request->integer('drill_uploader_id') ?: null;
        $drillBucket = $request->string('drill_bucket')->toString();
        if (! in_array($drillBucket, ['under_review', 'submitted', 'published'], true)) {
            $drillBucket = null;
        }

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
            'filters' => [
                'status' => $status,
                'board_id' => $boardId,
                'drill_grade_id' => $drillGradeId,
                'drill_uploader_id' => $drillUploaderId,
                'drill_bucket' => $drillBucket,
            ],
            'statuses' => $this->statusOptions(),
            'matrix' => $this->matrixService->build($boardId, $drillGradeId, $drillUploaderId),
            'uploaders' => $this->contentUploaders(),
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
        $boards = [];
        $selectedBoard = null;
        $selectedBoardId = null;

        if ($gradeLevel) {
            $boards = $this->classAssignment->boardsForGrade($gradeLevel);
            $selectedBoardId = $this->gradeContext->resolveBoardId($request);

            if (! $selectedBoardId) {
                $selectedBoardId = $this->classAssignment->defaultBoardIdForGrade($gradeLevel);
                if ($selectedBoardId) {
                    $this->gradeContext->persistBoard($request, $selectedBoardId);
                }
            }

            $boardIds = collect($boards)->pluck('id')->map(fn ($id) => (int) $id);
            if ($selectedBoardId && $boardIds->isNotEmpty() && ! $boardIds->contains($selectedBoardId)) {
                $selectedBoardId = $this->classAssignment->defaultBoardIdForGrade($gradeLevel);
                $this->gradeContext->persistBoard($request, $selectedBoardId);
            }

            $selectedBoard = collect($boards)->firstWhere('id', $selectedBoardId);
            $syllabus = $this->classAssignment->syllabusForGrade($gradeLevel, $selectedBoardId);

            $syllabusChapterIds = $syllabus
                ? $syllabus->chapters()->orderBy('sort_order')->pluck('id')
                : collect();

            $textbooks = Textbook::query()
                ->where('grade_level_id', $gradeLevel->id)
                ->orderBy('name')
                ->when($syllabusChapterIds->isNotEmpty(), function ($query) use ($syllabusChapterIds) {
                    $query->where(function ($inner) use ($syllabusChapterIds) {
                        $inner->whereDoesntHave('chapters')
                            ->orWhereHas('chapters', fn ($chapters) => $chapters->whereIn('syllabus_chapter_id', $syllabusChapterIds));
                    });
                })
                ->get(['id', 'name', 'code'])
                ->map(fn (Textbook $book) => [
                    'id' => $book->id,
                    'name' => $book->name,
                    'code' => $book->code,
                    'label' => "{$book->name} ({$book->code})",
                ])
                ->values()
                ->all();

            if ($syllabus) {
                $syllabus->load(['chapters' => fn ($q) => $q->orderBy('sort_order')]);

                $tasksForGrade = ContentUploadTask::query()
                    ->whereHas('textbookChapter.textbook', fn ($q) => $q->where('grade_level_id', $gradeLevel->id))
                    ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
                    ->with(['textbookChapter:id,textbook_id,syllabus_chapter_id,status,mcq_worksheet_id,mcq_worksheet_ids'])
                    ->get();

                $assignedBySyllabus = [];
                foreach ($tasksForGrade as $task) {
                    $syllabusId = (int) ($task->textbookChapter?->syllabus_chapter_id ?? 0);
                    $textbookId = (int) ($task->textbookChapter?->textbook_id ?? 0);
                    if ($syllabusId > 0 && $textbookId > 0) {
                        $assignedBySyllabus[$syllabusId][$textbookId] = true;
                    }
                }

                $textbookChapters = TextbookChapter::query()
                    ->whereHas('textbook', fn ($q) => $q->where('grade_level_id', $gradeLevel->id))
                    ->get(['id', 'textbook_id', 'syllabus_chapter_id', 'status', 'mcq_worksheet_id', 'mcq_worksheet_ids', 'extraction_items']);

                $uploadedBySyllabus = [];
                foreach ($textbookChapters as $textbookChapter) {
                    $syllabusId = (int) ($textbookChapter->syllabus_chapter_id ?? 0);
                    $textbookId = (int) $textbookChapter->textbook_id;
                    if ($syllabusId <= 0 || $textbookId <= 0) {
                        continue;
                    }

                    $isUploaded = $textbookChapter->status === TextbookChapter::STATUS_PUBLISHED
                        || $textbookChapter->mcqWorksheetIds() !== []
                        || (is_array($textbookChapter->extraction_items) && $textbookChapter->extraction_items !== []);

                    if (! $isUploaded) {
                        $hasUploadedTask = $tasksForGrade->contains(function (ContentUploadTask $task) use ($textbookChapter) {
                            return (int) $task->textbook_chapter_id === (int) $textbookChapter->id
                                && in_array($task->status, [
                                    ContentUploadTask::STATUS_UPLOADED,
                                    ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                                    ContentUploadTask::STATUS_VERIFIED,
                                    ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                                    ContentUploadTask::STATUS_PUBLISHED,
                                ], true);
                        });
                        $isUploaded = $hasUploadedTask;
                    }

                    if (! $isUploaded) {
                        continue;
                    }

                    $uploadedBySyllabus[$syllabusId][$textbookId] = true;
                }

                $syllabusChapters = $syllabus->chapters
                    ->map(function (SyllabusChapter $chapter) use ($gradeLevel, $uploadedBySyllabus, $assignedBySyllabus) {
                        $syllabusId = (int) $chapter->id;
                        $rate = $this->rateCardService->resolveRateForSyllabusChapter(
                            $gradeLevel->id,
                            $chapter,
                        );

                        return [
                            'id' => $chapter->id,
                            'chapter_number' => $chapter->chapter_number,
                            'name' => $chapter->name,
                            'label' => self::chapterLabel($chapter),
                            'default_amount_inr' => $rate['amount_inr'],
                            'default_rate_basis' => $rate['rate_basis'],
                            'assigned_for_textbooks' => array_keys($assignedBySyllabus[$syllabusId] ?? []),
                            'uploaded_for_textbooks' => array_keys($uploadedBySyllabus[$syllabusId] ?? []),
                        ];
                    })
                    ->values()
                    ->all();
            }
        }

        $classDefault = $gradeLevel
            ? $this->rateCardService->resolveClassDefaultRate($gradeLevel->id)
            : ['amount_inr' => 0, 'rate_basis' => ContentRateCard::BASIS_PER_QUESTION];

        return Inertia::render('Admin/ContentTasks/Create', [
            'uploaders' => $uploaders,
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'boards' => $boards,
            'selectedBoard' => $selectedBoard ? [
                'id' => $selectedBoard['id'],
                'code' => $selectedBoard['code'] ?? '',
                'name' => $selectedBoard['name'] ?? '',
            ] : null,
            'selectedBoardId' => $selectedBoardId,
            'textbooks' => $textbooks,
            'syllabusChapters' => $syllabusChapters,
            'classDefaultRateInr' => $classDefault['amount_inr'],
            'classDefaultRateBasis' => $classDefault['rate_basis'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        abort_unless($gradeLevel, 422, 'Select a class from the top bar first.');

        $validated = $request->validate([
            'assigned_to_user_id' => ['required', 'exists:users,id'],
            'board_id' => ['nullable', 'integer', 'exists:boards,id'],
            'textbook_id' => ['nullable', 'integer', Rule::exists('textbooks', 'id')],
            'book_name' => ['required_without:textbook_id', 'nullable', 'string', 'max:255'],
            'book_code' => ['required_without:textbook_id', 'nullable', 'string', 'max:32', 'alpha_dash'],
            'syllabus_chapter_ids' => ['required', 'array', 'min:1'],
            'syllabus_chapter_ids.*' => ['integer', 'exists:syllabus_chapters,id'],
            'rate_basis' => ['required', Rule::in([
                ContentRateCard::BASIS_PER_SET,
                ContentRateCard::BASIS_PER_QUESTION,
            ])],
            'offered_amount_inr' => ['nullable', 'integer', 'min:1', 'max:500000'],
            'duplicate_override_reason' => ['nullable', 'string', 'max:2000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $boardId = isset($validated['board_id'])
            ? (int) $validated['board_id']
            : $this->gradeContext->resolveBoardId($request);

        if ($boardId) {
            $this->gradeContext->persistBoard($request, $boardId);
        }

        $syllabus = $this->classAssignment->syllabusForGrade($gradeLevel, $boardId);
        $allowedChapterIds = $syllabus
            ? $syllabus->chapters()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $unknownChapters = array_diff(
            array_map('intval', $validated['syllabus_chapter_ids']),
            $allowedChapterIds,
        );

        if ($unknownChapters !== []) {
            return back()
                ->withInput()
                ->withErrors([
                    'syllabus_chapter_ids' => 'Select syllabus chapters from the chosen board.',
                ]);
        }

        $rateBasis = $validated['rate_basis'];
        $minAmount = $rateBasis === ContentRateCard::BASIS_PER_QUESTION ? 1 : 100;

        if (isset($validated['offered_amount_inr']) && (int) $validated['offered_amount_inr'] < $minAmount) {
            return back()
                ->withInput()
                ->withErrors([
                    'offered_amount_inr' => $rateBasis === ContentRateCard::BASIS_PER_QUESTION
                        ? 'Per-question rate must be at least ₹1.'
                        : 'Per-chapter rate must be at least ₹100.',
                ]);
        }

        $uploader = User::query()->findOrFail($validated['assigned_to_user_id']);

        if (! $uploader->isContentUploader()) {
            return back()->with('error', 'Selected user is not a content uploader.');
        }

        $amountOverride = isset($validated['offered_amount_inr'])
            ? (int) $validated['offered_amount_inr']
            : null;

        if ($amountOverride === null || $amountOverride <= 0) {
            $missingRate = false;

            foreach ($validated['syllabus_chapter_ids'] as $syllabusChapterId) {
                $syllabusChapter = SyllabusChapter::query()->find($syllabusChapterId);
                if (! $syllabusChapter) {
                    continue;
                }

                $rate = $this->rateCardService->resolveRateForSyllabusChapter($gradeLevel->id, $syllabusChapter);
                if ($rate['amount_inr'] <= 0) {
                    $missingRate = true;
                    break;
                }
            }

            if ($missingRate) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'offered_amount_inr' => 'No rate in the matrix for this class. Enter a rate override above, or set class rates in the rate matrix first.',
                    ]);
            }
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

            $blocked = [];
            foreach ($validated['syllabus_chapter_ids'] as $syllabusChapterId) {
                $existing = TextbookChapter::query()
                    ->where('textbook_id', $textbook->id)
                    ->where('syllabus_chapter_id', $syllabusChapterId)
                    ->first();

                if (! $existing) {
                    continue;
                }

                $guard = app(ContentDuplicateGuardService::class)->check($existing);
                if ($guard['blocked']) {
                    $blocked[] = $existing->title ?: ('chapter #'.$syllabusChapterId);
                }
            }

            if ($blocked !== []) {
                return back()
                    ->withInput()
                    ->with('error', 'Already uploaded / assigned — cannot re-select: '.implode(', ', $blocked));
            }

            $result = $this->taskService->assignSyllabusChapters(
                $textbook,
                $validated['syllabus_chapter_ids'],
                $uploader,
                $request->user(),
                $amount,
                $amount !== null ? $rateBasis : null,
                $validated['duplicate_override_reason'] ?? null,
                $validated['admin_notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        $count = count($result['tasks']);
        $emailSent = (bool) $result['email_sent'];

        $success = $count === 1
            ? "1 chapter assigned to {$uploader->name}."
            : "{$count} chapters assigned to {$uploader->name}.";

        $success .= $emailSent
            ? " Assignment email sent to {$uploader->email}."
            : ' Warning: assignment email could not be sent — check mail settings.';

        return redirect()
            ->route('admin.content-tasks.index')
            ->with('success', $success)
            ->with('email_sent', $emailSent)
            ->with('assignment_summary', [
                'uploader' => $uploader->only(['id', 'name', 'email']),
                'count' => $count,
            ]);
    }

    public function show(Request $request, ContentUploadTask $contentTask): Response
    {
        $contentTask->load([
            'assignee:id,name,email',
            'assigner:id,name',
            'textbookChapter.textbook.gradeLevel',
        ]);

        $verification = $this->verificationPayload($contentTask, $request->user());
        $contentTask->refresh()->load([
            'assignee:id,name,email',
            'assigner:id,name',
            'textbookChapter.textbook.gradeLevel',
        ]);

        return Inertia::render('Admin/ContentTasks/Show', [
            'task' => $this->serializeTask($contentTask, detailed: true),
            'verification' => $verification,
            'activeSeconds' => $this->sessionService->totalActiveSeconds($contentTask),
            'deleteRequests' => $this->chapterLibrary->pendingDeleteRequestsForTask($contentTask),
            'uploaders' => $this->contentUploaders(),
            'textbooks' => $contentTask->textbookChapter?->textbook
                ? $this->bookService->textbooksForGrade((int) $contentTask->textbookChapter->textbook->grade_level_id)
                : [],
        ]);
    }

    public function reassign(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $validated = $request->validate([
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $uploader = User::query()->findOrFail($validated['assigned_to_user_id']);

        try {
            $result = $this->taskService->reassign(
                $contentTask,
                $uploader,
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $success = "Reassigned from {$result['previous_name']} to {$uploader->name}.";
        $success .= $result['email_sent']
            ? " Assignment email sent to {$uploader->email}."
            : ' Warning: assignment email could not be sent — check mail settings.';

        return back()
            ->with('success', $success)
            ->with('email_sent', $result['email_sent']);
    }

    public function bulkReassign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'task_ids' => ['required', 'array', 'min:1', 'max:50'],
            'task_ids.*' => ['integer', 'exists:content_upload_tasks,id'],
            'assigned_to_user_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $uploader = User::query()->findOrFail($validated['assigned_to_user_id']);

        try {
            $result = $this->taskService->reassignMany(
                $validated['task_ids'],
                $uploader,
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['moved_count'] === 0) {
            $firstSkip = $result['skipped'][0] ?? 'None of the selected chapters could be reassigned.';

            return back()->with('error', $firstSkip);
        }

        $from = $result['previous_names'] !== []
            ? ' from '.implode(', ', $result['previous_names'])
            : '';
        $success = "Reassigned {$result['moved_count']} chapter(s){$from} to {$uploader->name}.";

        if ($result['skipped_count'] > 0) {
            $success .= ' '.$result['skipped_count'].' skipped.';
        }

        $success .= $result['email_sent']
            ? " Assignment email sent to {$uploader->email}."
            : ' Warning: assignment email could not be sent — check mail settings.';

        return back()
            ->with('success', $success)
            ->with('email_sent', $result['email_sent']);
    }

    public function saveVerificationQuestion(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'question_text' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'method_hint' => ['nullable', 'string', 'max:2000'],
            'difficulty' => ['nullable', 'string', 'max:64'],
            'options' => ['required', 'array', 'min:2', 'max:8'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.option_text' => ['required', 'string', 'max:2000'],
            'options.*.is_correct' => ['required', 'boolean'],
        ]);

        $run = $this->authorizeVerificationRun($contentTask, (int) $validated['run_id']);

        try {
            $this->verificationService->saveQuestion(
                $run,
                (int) $validated['question_id'],
                $validated,
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Question saved.');
    }

    public function markVerificationBatch(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
        ]);

        $run = $this->authorizeVerificationRun($contentTask, (int) $validated['run_id']);

        try {
            $marked = $this->verificationService->markVerifiedBatch(
                $run,
                $validated['question_ids'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $run->refresh();
        $contentTask->refresh();

        if ($contentTask->status === ContentUploadTask::STATUS_VERIFIED) {
            return back()->with(
                'success',
                "Marked {$marked} question(s) verified. All done — use Publish below to finish.",
            );
        }

        return back()->with('success', "Marked {$marked} question(s) verified.");
    }

    public function uploadVerificationDiagram(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'diagram' => ['required', 'image', 'max:5120'],
        ]);

        $run = $this->authorizeVerificationRun($contentTask, (int) $validated['run_id']);

        try {
            $this->verificationService->attachDiagram(
                $run,
                (int) $validated['question_id'],
                $request->file('diagram'),
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Figure uploaded for this question.');
    }

    public function removeVerificationDiagram(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
        ]);

        $run = $this->authorizeVerificationRun($contentTask, (int) $validated['run_id']);

        try {
            $this->verificationService->removeDiagram(
                $run,
                (int) $validated['question_id'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Figure removed.');
    }

    public function returnForReverification(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array'],
            'items.*.question_id' => ['required', 'integer', 'exists:questions,id'],
            'items.*.remark' => ['nullable', 'string', 'max:500'],
            'items.*.number' => ['nullable', 'integer', 'min:1'],
            'items.*.question_text' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->taskService->returnForReverification(
                $contentTask,
                $request->user(),
                $validated['reason'] ?? null,
                $validated['items'] ?? [],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $count = count($validated['items'] ?? []);
        $message = $count > 0
            ? "Sent {$count} question(s) back to uploader. They will be emailed."
            : 'Sent back to uploader for re-verification. They will be emailed.';

        return back()->with('success', $message);
    }

    public function returnHelpRequestQuestion(Request $request, QuestionResolutionItem $item): RedirectResponse
    {
        abort_unless($item->status === QuestionResolutionItem::STATUS_PENDING, 404);

        $validated = $request->validate([
            'issue' => ['required', 'in:wrong_answer,incomplete,other'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['issue'] === 'other' && ! filled(trim((string) ($validated['remark'] ?? '')))) {
            return back()->with('error', 'Add a short note so the uploader knows what to fix.');
        }

        $item->loadMissing('question');

        if (! $item->question) {
            return back()->with('error', 'This question is no longer available.');
        }

        try {
            $this->taskService->returnHelpRequestQuestion(
                $item->question,
                $request->user(),
                $validated['issue'],
                $validated['remark'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'This sum is on the uploader dashboard to correct. They get an email when they open it.');
    }

    public function publish(ContentUploadTask $contentTask, Request $request): RedirectResponse
    {
        try {
            $this->taskService->publish($contentTask, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Task marked published.');
    }

    public function approveQuestionDelete(Request $request, ContentUploadTask $contentTask, ContentQuestionDeleteRequest $deleteRequest): RedirectResponse
    {
        abort_unless((int) $deleteRequest->content_upload_task_id === (int) $contentTask->id, 404);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->chapterQuestions->approveDeleteRequest(
                $deleteRequest,
                $request->user(),
                $validated['admin_note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Question deleted.');
    }

    public function rejectQuestionDelete(Request $request, ContentUploadTask $contentTask, ContentQuestionDeleteRequest $deleteRequest): RedirectResponse
    {
        abort_unless((int) $deleteRequest->content_upload_task_id === (int) $contentTask->id, 404);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->chapterQuestions->rejectDeleteRequest(
                $deleteRequest,
                $request->user(),
                $validated['admin_note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Delete request rejected.');
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
            'rate_basis' => $task->rate_basis,
            'rate_basis_label' => $task->rateBasisLabel(),
            'rate_description' => $task->rateDescription(),
            'payable_amount_inr' => $task->payableAmountInr(),
            'offered_amount_inr' => $task->offered_amount_inr,
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'agreed_at' => $task->agreed_at?->toIso8601String(),
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'published_at' => $task->published_at?->toIso8601String(),
            'assignee' => $task->assignee?->only(['id', 'name', 'email']),
            'can_reassign' => $task->canReassign(),
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'status' => $chapter->status,
                'textbook_name' => $chapter->textbook?->name,
                'textbook_code' => $chapter->textbook?->code,
                'textbook_id' => $chapter->textbook_id,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
                'has_pdf' => $this->bookService->hasStoredPdf($chapter),
                'pdf_url' => $chapter->pdfUrl(),
            ] : null,
        ];

        if ($detailed) {
            $data['duplicate_override_reason'] = $task->duplicate_override_reason;
            $data['admin_notes'] = $task->admin_notes;
            $data['assigner'] = $task->assigner?->only(['id', 'name']);
            $data['can_return_for_reverification'] = in_array($task->status, [
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_VERIFIED,
                ContentUploadTask::STATUS_PUBLISHED,
                ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ], true);
            $data['can_verify_questions'] = in_array($task->status, [
                ContentUploadTask::STATUS_UPLOADED,
                ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                ContentUploadTask::STATUS_VERIFIED,
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_PUBLISHED,
            ], true);
            $data['can_publish'] = in_array($task->status, [
                ContentUploadTask::STATUS_VERIFIED,
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ], true);
        }

        return $data;
    }

    /**
     * @return array{
     *     run_id: int,
     *     questions: list<array<string, mixed>>,
     *     summary: array<string, int>,
     *     set_plan: list<array<string, mixed>>,
     *     set_plan_summary: string,
     *     set_plan_parts: int
     * }|null
     */
    private function verificationPayload(ContentUploadTask $task, User $user): ?array
    {
        if (! in_array($task->status, [
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
        ], true)) {
            return null;
        }

        if ($task->textbookChapter?->mcqWorksheetIds() === []) {
            return null;
        }

        $verification = $this->verificationService->forTask($task, $user);
        $this->verificationService->maybeCompleteRunIfAllVerified($verification['run']);

        $chapter = $task->textbookChapter;
        $setPlan = is_array($chapter?->mcq_set_plan) ? $chapter->mcq_set_plan : [];

        return [
            'run_id' => $verification['run']->id,
            'questions' => $verification['questions'],
            'summary' => $verification['summary'],
            'set_plan' => collect($setPlan)->values()->map(function (array $row, int $index) {
                $from = (int) ($row['q_from'] ?? 0);
                $to = (int) ($row['q_to'] ?? 0);

                return [
                    'part' => $index + 1,
                    'set_code' => (string) ($row['set_code'] ?? ''),
                    'q_from' => $from,
                    'q_to' => $to,
                    'question_count' => max(0, $to - $from + 1),
                    'description' => trim((string) ($row['description'] ?? '')),
                ];
            })->all(),
            'set_plan_summary' => $this->setPlanService->summary($setPlan),
            'set_plan_parts' => count($setPlan),
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, name: string, email: string}>
     */
    private function contentUploaders()
    {
        return User::query()
            ->whereHas('groups', fn ($q) => $q->where('code', User::ROLE_CONTENT_UPLOADER))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function authorizeVerificationRun(ContentUploadTask $task, int $runId): ContentVerificationRun
    {
        $run = ContentVerificationRun::query()->findOrFail($runId);

        if ($run->content_upload_task_id !== $task->id) {
            abort(403);
        }

        return $run;
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
