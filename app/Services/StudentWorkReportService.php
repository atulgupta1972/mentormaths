<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\BasicsDrillSession;
use App\Models\FormulaDrillSession;
use App\Models\GradeLevel;
use App\Models\SetAttempt;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Support\AssignmentProgress;
use App\Support\ProgressSummaryTable;
use App\Support\WorksheetDeliveryMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StudentWorkReportService
{
    private const LIVE_MINUTES = 20;

    public function __construct(
        private StudentProgressSummaryService $summaryService,
        private ClassAssignmentService $classAssignmentService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(GradeLevel $gradeLevel, ?int $boardId = null): array
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return $this->emptyReport();
        }

        $boardId = $boardId ?? $this->classAssignmentService->defaultBoardIdForGrade($gradeLevel);

        $enrollments = StudentEnrollment::query()
            ->with(['student:id,name,user_id', 'gradeLevel:id,name'])
            ->where('academic_year_id', $activeYear->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->when($boardId, fn ($query) => $query->where('board_id', $boardId))
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->whereHas('student')
            ->get()
            ->sortBy(fn (StudentEnrollment $enrollment) => $enrollment->student->name)
            ->values();

        if ($enrollments->isEmpty()) {
            return $this->emptyReport($boardId);
        }

        $studentIds = $enrollments->pluck('student_id');
        $asOf = now()->endOfDay();
        $userIds = $enrollments->pluck('student.user_id')->filter()->map(fn ($id) => (int) $id)->all();

        $onlineUserIds = $this->recentlyOnlineUserIds($userIds);
        $liveByStudent = $this->liveActivities($enrollments, $studentIds, $onlineUserIds)->groupBy('student_id');

        // Anyone with live work is online.
        $liveUserIds = \App\Models\Student::query()
            ->whereIn('id', $liveByStudent->keys()->all())
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $onlineUserIds = array_values(array_unique([...$onlineUserIds, ...$liveUserIds]));

        $students = [];
        $totalPendingItems = 0;
        $studentsWithPending = 0;

        foreach ($enrollments as $enrollment) {
            $balance = $this->summaryService->buildBalanceReminder($enrollment, $asOf);
            $pendingItems = $this->formatPendingItems(
                array_merge($balance['overdue'] ?? [], $balance['pending'] ?? []),
                $asOf,
            );
            $helpItems = array_map(fn (array $item) => [
                'type' => 'correction',
                'assignment_id' => null,
                'title' => $item['set_code']
                    ? 'Correction · '.$item['set_code']
                    : 'Help request',
                'kind_label' => 'Correction',
                'is_overdue' => true,
                'progress_label' => 'Pending',
                'progress_done' => 0,
                'progress_total' => 1,
                'pending_days_label' => $item['pending_days_label'] ?? '—',
                'status' => 'overdue',
            ], $balance['help_requests'] ?? []);

            $allPending = array_merge($pendingItems, $helpItems);
            $liveRows = $liveByStudent->get($enrollment->student_id, collect())->values()->all();
            $isOnline = $enrollment->student?->user_id
                && in_array((int) $enrollment->student->user_id, $onlineUserIds, true);

            if ($allPending !== []) {
                $studentsWithPending++;
                $totalPendingItems += count($allPending);
            }

            $students[] = [
                'id' => $enrollment->student_id,
                'enrollment_id' => $enrollment->id,
                'name' => $enrollment->student->name,
                'pending_count' => count($allPending),
                'overdue_count' => count($balance['overdue'] ?? []) + count($balance['help_requests'] ?? []),
                'pending_items' => $allPending,
                'live_activities' => $liveRows,
                'is_online' => $isOnline,
                'show_url' => route('admin.students.show', $enrollment->student_id),
            ];
        }

        $live = $liveByStudent
            ->flatten(1)
            ->sortByDesc('last_active_at')
            ->values()
            ->all();

        return [
            'board_id' => $boardId,
            'live' => $live,
            'students' => $students,
            'summary' => [
                'total_students' => $enrollments->count(),
                'students_with_pending' => $studentsWithPending,
                'students_live_now' => count(array_unique(array_column($live, 'student_id'))),
                'students_online' => collect($students)->where('is_online', true)->count(),
                'total_pending_items' => $totalPendingItems,
            ],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StudentEnrollment>  $enrollments
     * @param  \Illuminate\Support\Collection<int, int>  $studentIds
     * @param  list<int>  $onlineUserIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function liveActivities($enrollments, $studentIds, array $onlineUserIds = [])
    {
        $cutoff = now()->subMinutes(self::LIVE_MINUTES);
        $enrollmentByStudent = $enrollments->keyBy('student_id');
        $rows = collect();

        $onlineStudentIds = $onlineUserIds === []
            ? []
            : \App\Models\Student::query()
                ->whereIn('user_id', $onlineUserIds)
                ->whereIn('id', $studentIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        // Live = open timing session, recent attempt/answer activity, or in-progress while student is online.
        $attempts = SetAttempt::query()
            ->where('status', SetAttempt::STATUS_IN_PROGRESS)
            ->where(function ($query) use ($cutoff, $onlineStudentIds) {
                $query->whereNotNull('active_session_started_at')
                    ->orWhere('updated_at', '>=', $cutoff)
                    ->orWhere('started_at', '>=', $cutoff)
                    ->orWhereHas('answers', fn ($q) => $q->where('updated_at', '>=', $cutoff))
                    ->orWhereHas('guidedQuestions', fn ($q) => $q->where('updated_at', '>=', $cutoff));

                if ($onlineStudentIds !== []) {
                    $query->orWhereHas(
                        'assignment.enrollment',
                        fn ($q) => $q->whereIn('student_id', $onlineStudentIds),
                    );
                }
            })
            ->whereHas('assignment', fn ($query) => $query
                ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
                ->whereNot('status', SetAssignment::STATUS_CANCELLED))
            ->with([
                'assignment.enrollment.student:id,name',
                'assignment.enrollment.gradeLevel:id,name',
                'assignment.practiceSet' => fn ($query) => $query->withCount('questions'),
                'guidedQuestions',
                'answers',
            ])
            ->get();

        foreach ($attempts as $attempt) {
            $assignment = $attempt->assignment;
            $enrollment = $assignment?->enrollment;
            $student = $enrollment?->student;

            if (! $student || ! $assignment) {
                continue;
            }

            $partial = AssignmentProgress::partialProgress($assignment);
            $worksheet = $assignment->practiceSet;
            $kind = $worksheet?->isChapterTest() ? 'Test' : ($worksheet?->isCatchUp() ? 'Catch-up' : 'Practice');
            $answerStamp = $attempt->answers->max('updated_at');
            $guidedStamp = $attempt->guidedQuestions->max('updated_at');
            $lastActive = collect([
                $attempt->updated_at,
                $attempt->active_session_started_at,
                $attempt->started_at,
                $answerStamp,
                $guidedStamp,
            ])->filter()->sortDesc()->first();

            // Open session still counting → treat as active now.
            if ($attempt->active_session_started_at) {
                $lastActive = now();
            }

            $lastActiveAt = $lastActive
                ? Carbon::parse($lastActive)->toIso8601String()
                : null;

            $rows->push($this->liveRow(
                studentId: $student->id,
                studentName: $student->name,
                className: $enrollment->gradeLevel?->name,
                activityType: 'assignment',
                activityLabel: trim(($kind.' · '.($worksheet?->display_title ?? 'Worksheet'))),
                progressLabel: $partial['label'],
                progressDone: $partial['done'],
                progressTotal: $partial['total'],
                lastActiveAt: $lastActiveAt,
                assignmentId: $assignment->id,
            ));
        }

        $today = now()->toDateString();

        $formulaSessions = FormulaDrillSession::query()
            ->whereIn('student_id', $studentIds)
            ->whereDate('drill_date', $today)
            ->where(function ($query) use ($cutoff, $onlineStudentIds) {
                $query->where('status', FormulaDrillSession::STATUS_IN_PROGRESS)
                    ->where(function ($inner) use ($cutoff, $onlineStudentIds) {
                        $inner->where('updated_at', '>=', $cutoff);

                        if ($onlineStudentIds !== []) {
                            $inner->orWhereIn('student_id', $onlineStudentIds);
                        }
                    });
            })
            ->with('student:id,name')
            ->get();

        foreach ($formulaSessions as $session) {
            $enrollment = $enrollmentByStudent->get($session->student_id);
            $total = max((int) $session->questions_total, 1);
            $done = (int) $session->questions_completed;

            $rows->push($this->liveRow(
                studentId: $session->student_id,
                studentName: $session->student?->name ?? 'Student',
                className: $enrollment?->gradeLevel?->name,
                activityType: 'formula_drill',
                activityLabel: 'Formula drill (today)',
                progressLabel: "{$done}/{$total}",
                progressDone: $done,
                progressTotal: $total,
                lastActiveAt: ($session->updated_at ?? now())?->toIso8601String(),
                assignmentId: null,
            ));
        }

        $basicsSessions = BasicsDrillSession::query()
            ->whereIn('student_id', $studentIds)
            ->whereDate('drill_date', $today)
            ->where(function ($query) use ($cutoff, $onlineStudentIds) {
                $query->where('status', BasicsDrillSession::STATUS_IN_PROGRESS)
                    ->where(function ($inner) use ($cutoff, $onlineStudentIds) {
                        $inner->where('updated_at', '>=', $cutoff);

                        if ($onlineStudentIds !== []) {
                            $inner->orWhereIn('student_id', $onlineStudentIds);
                        }
                    });
            })
            ->with(['student:id,name', 'items'])
            ->get();

        foreach ($basicsSessions as $session) {
            $enrollment = $enrollmentByStudent->get($session->student_id);
            $done = $session->items->whereIn('status', ['correct', 'revealed'])->count();
            $total = max($session->items->count(), 1);

            $rows->push($this->liveRow(
                studentId: $session->student_id,
                studentName: $session->student?->name ?? 'Student',
                className: $enrollment?->gradeLevel?->name,
                activityType: 'basics_drill',
                activityLabel: 'Basics drill · '.str_replace('_', ' ', (string) $session->phase),
                progressLabel: "{$done}/{$total}",
                progressDone: $done,
                progressTotal: $total,
                lastActiveAt: ($session->updated_at ?? now())?->toIso8601String(),
                assignmentId: null,
            ));
        }

        return $rows;
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function recentlyOnlineUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $online = collect();
        $cutoff = now()->subMinutes(self::LIVE_MINUTES);

        $online = $online->merge(
            \App\Models\User::query()
                ->whereIn('id', $userIds)
                ->where('last_seen_at', '>=', $cutoff)
                ->pluck('id')
                ->map(fn ($id) => (int) $id),
        );

        $sessionCutoff = $cutoff->getTimestamp();

        try {
            if (config('session.driver') === 'database') {
                $online = $online->merge(
                    DB::table('sessions')
                        ->whereIn('user_id', $userIds)
                        ->where('last_activity', '>=', $sessionCutoff)
                        ->pluck('user_id')
                        ->map(fn ($id) => (int) $id),
                );
            }
        } catch (\Throwable) {
            // Session table may be missing / non-database driver.
        }

        return $online->unique()->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function formatPendingItems(array $rows, Carbon $asOf): array
    {
        $assignmentIds = collect($rows)
            ->pluck('assignment_id')
            ->filter()
            ->unique()
            ->values();

        $assignments = $assignmentIds->isEmpty()
            ? collect()
            : SetAssignment::query()
                ->whereIn('id', $assignmentIds)
                ->with([
                    'practiceSet' => fn ($query) => $query->withCount('questions'),
                    'attempts' => fn ($query) => $query->orderByDesc('attempt_number'),
                    'attempts.guidedQuestions',
                    'attempts.answers',
                    'writtenSubmissions' => fn ($query) => $query->orderByDesc('id'),
                ])
                ->get()
                ->keyBy('id');

        return collect($rows)->map(function (array $row) use ($asOf, $assignments) {
            $assignment = $assignments->get($row['assignment_id'] ?? 0);
            $partial = $assignment
                ? AssignmentProgress::partialProgress($assignment)
                : ['done' => 0, 'total' => (int) ($row['question_count'] ?? 1), 'label' => '0/'.($row['question_count'] ?? '?'), 'started' => false];

            $meta = ProgressSummaryTable::pendingDaysMeta($row, $asOf);

            return [
                'type' => 'assignment',
                'assignment_id' => $row['assignment_id'] ?? null,
                'title' => $row['display_title'] ?? $row['topic_name'] ?? 'Worksheet',
                'kind_label' => $row['kind_label'] ?? 'Work',
                'chapter_name' => $row['chapter_name'] ?? null,
                'is_overdue' => (bool) ($row['is_overdue'] ?? false),
                'progress_label' => $partial['label'],
                'progress_done' => $partial['done'],
                'progress_total' => $partial['total'],
                'started' => $partial['started'],
                'pending_days_label' => $meta['pending_days_label'] ?? '—',
                'target_date' => $row['target_date'] ?? null,
                'status' => ($row['is_overdue'] ?? false) ? 'overdue' : 'pending',
                'delivery_mode' => $row['delivery_mode'] ?? WorksheetDeliveryMode::ONLINE,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function liveRow(
        int $studentId,
        string $studentName,
        ?string $className,
        string $activityType,
        string $activityLabel,
        string $progressLabel,
        int $progressDone,
        int $progressTotal,
        ?string $lastActiveAt,
        ?int $assignmentId,
    ): array {
        return [
            'student_id' => $studentId,
            'student_name' => $studentName,
            'class_name' => $className,
            'activity_type' => $activityType,
            'activity_label' => $activityLabel,
            'progress_label' => $progressLabel,
            'progress_done' => $progressDone,
            'progress_total' => $progressTotal,
            'last_active_at' => $lastActiveAt,
            'assignment_id' => $assignmentId,
            'assignment_url' => $assignmentId
                ? route('admin.set-assignments.show', $assignmentId)
                : null,
            'student_url' => route('admin.students.show', $studentId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyReport(?int $boardId = null): array
    {
        return [
            'board_id' => $boardId,
            'live' => [],
            'students' => [],
            'summary' => [
                'total_students' => 0,
                'students_with_pending' => 0,
                'students_live_now' => 0,
                'students_online' => 0,
                'total_pending_items' => 0,
            ],
        ];
    }
}
