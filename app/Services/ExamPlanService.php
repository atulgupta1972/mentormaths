<?php

namespace App\Services;

use App\Models\ExamPlan;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\AssignmentProgress;
use App\Support\PracticeSetScope;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ExamPlanService
{
    public function syllabusVersionForEnrollment(StudentEnrollment $enrollment): ?SyllabusVersion
    {
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $maths) {
            return null;
        }

        return SyllabusVersion::query()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('grade_level_id', $enrollment->grade_level_id)
            ->where('board_id', $enrollment->board_id)
            ->where('subject_id', $maths->id)
            ->first();
    }

    public function chapterOptionsForEnrollment(StudentEnrollment $enrollment): Collection
    {
        $syllabusVersion = $this->syllabusVersionForEnrollment($enrollment);

        if (! $syllabusVersion) {
            return collect();
        }

        return SyllabusChapter::query()
            ->where('syllabus_version_id', $syllabusVersion->id)
            ->with(['topics' => fn ($query) => $query->orderBy('sort_order')->select('id', 'syllabus_chapter_id', 'name', 'sort_order')])
            ->orderBy('sort_order')
            ->get(['id', 'chapter_number', 'name'])
            ->map(fn (SyllabusChapter $chapter) => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
                'label' => self::chapterLabel($chapter),
                'topics' => $chapter->topics->map(fn (SyllabusTopic $topic) => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                ])->values()->all(),
            ]);
    }

    /**
     * @param  list<array{syllabus_chapter_id: int, syllabus_topic_ids?: list<int>|null}>  $selections
     * @return array{chapter_ids: list<int>, topic_ids: list<int>}
     */
    public function normalizeChapterSelections(array $selections, StudentEnrollment $enrollment): array
    {
        if ($selections === []) {
            throw ValidationException::withMessages([
                'chapter_selections' => 'Select at least one chapter for this exam.',
            ]);
        }

        $chaptersById = $this->chapterOptionsForEnrollment($enrollment)->keyBy('id');
        $chapterIds = [];
        $topicIds = [];

        foreach ($selections as $selection) {
            $chapterId = (int) ($selection['syllabus_chapter_id'] ?? 0);
            $chapter = $chaptersById->get($chapterId);

            if (! $chapter) {
                throw ValidationException::withMessages([
                    'chapter_selections' => 'One or more chapters are not part of this class syllabus.',
                ]);
            }

            $chapterIds[] = $chapterId;
            $requestedTopicIds = $selection['syllabus_topic_ids'] ?? null;

            if ($requestedTopicIds === null || $requestedTopicIds === []) {
                continue;
            }

            $allowedTopicIds = collect($chapter['topics'])->pluck('id')->all();
            $invalidTopics = array_diff($requestedTopicIds, $allowedTopicIds);

            if ($invalidTopics !== []) {
                throw ValidationException::withMessages([
                    'chapter_selections' => 'One or more topics are not part of the selected chapter.',
                ]);
            }

            if (count($requestedTopicIds) >= count($allowedTopicIds)) {
                continue;
            }

            foreach ($requestedTopicIds as $topicId) {
                $topicIds[] = (int) $topicId;
            }
        }

        return [
            'chapter_ids' => array_values(array_unique($chapterIds)),
            'topic_ids' => array_values(array_unique($topicIds)),
        ];
    }

    /**
     * @param  list<array{syllabus_chapter_id: int, syllabus_topic_ids?: list<int>|null}>  $selections
     */
    public function syncChapterSelections(ExamPlan $plan, array $selections, StudentEnrollment $enrollment): void
    {
        $normalized = $this->normalizeChapterSelections($selections, $enrollment);

        $plan->chapters()->sync($normalized['chapter_ids']);
        $plan->topics()->sync($normalized['topic_ids']);
    }

    /**
     * @param  list<int>  $chapterIds
     *
     * @deprecated Use normalizeChapterSelections() for topic-aware plans.
     */
    public function assertChaptersBelongToEnrollment(array $chapterIds, StudentEnrollment $enrollment): void
    {
        $allowed = $this->chapterOptionsForEnrollment($enrollment)->pluck('id')->all();
        $invalid = array_diff($chapterIds, $allowed);

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'syllabus_chapter_ids' => 'One or more chapters are not part of this class syllabus.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{syllabus_chapter_id: int, syllabus_topic_ids?: list<int>|null}>  $selections
     */
    public function create(StudentEnrollment $enrollment, User $creator, array $data, array $selections): ExamPlan
    {
        $plan = ExamPlan::create([
            'student_enrollment_id' => $enrollment->id,
            'exam_date' => $data['exam_date'],
            'title' => $data['title'],
            'exam_type' => $data['exam_type'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $creator->id,
            'status' => ExamPlan::STATUS_PLANNED,
        ]);

        $this->syncChapterSelections($plan, $selections, $enrollment);

        return $plan->load(['chapters', 'topics']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{syllabus_chapter_id: int, syllabus_topic_ids?: list<int>|null}>  $selections
     */
    public function update(ExamPlan $plan, array $data, array $selections): ExamPlan
    {
        $plan->update([
            'exam_date' => $data['exam_date'],
            'title' => $data['title'],
            'exam_type' => $data['exam_type'],
            'notes' => $data['notes'] ?? null,
        ]);

        $this->syncChapterSelections($plan, $selections, $plan->enrollment);

        return $plan->fresh(['chapters', 'topics']);
    }

    public function formatPlan(ExamPlan $plan, bool $includePrep = true, bool $includeAssignables = false): array
    {
        $plan->loadMissing([
            'chapters:id,chapter_number,name,sort_order',
            'topics:id,syllabus_chapter_id,name,sort_order',
        ]);

        $topicsByChapter = $plan->topics->groupBy('syllabus_chapter_id');

        $chapterSelections = $plan->chapters->map(function (SyllabusChapter $chapter) use ($topicsByChapter) {
            $selectedTopics = $topicsByChapter->get($chapter->id, collect());

            return [
                'syllabus_chapter_id' => $chapter->id,
                'syllabus_topic_ids' => $selectedTopics->isEmpty()
                    ? null
                    : $selectedTopics->pluck('id')->values()->all(),
            ];
        })->values()->all();

        $chapterNames = $plan->chapters->map(
            fn (SyllabusChapter $chapter) => self::chapterSelectionLabel(
                $chapter,
                $topicsByChapter->get($chapter->id, collect()),
            ),
        )->values()->all();

        $data = [
            'id' => $plan->id,
            'exam_date' => $plan->exam_date->toDateString(),
            'suggested_due_date' => $plan->exam_date->copy()->subDay()->toDateString(),
            'title' => $plan->title,
            'exam_type' => $plan->exam_type,
            'exam_type_label' => $plan->typeLabel(),
            'notes' => $plan->notes,
            'status' => $plan->status,
            'is_upcoming' => $plan->isUpcoming(),
            'chapters' => $plan->chapters->map(fn (SyllabusChapter $chapter) => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
                'label' => self::chapterLabel($chapter),
            ])->values()->all(),
            'chapter_ids' => $plan->chapters->pluck('id')->all(),
            'chapter_selections' => $chapterSelections,
            'chapter_names' => $chapterNames,
        ];

        if ($includePrep) {
            $data['prep_assignments'] = $this->prepAssignmentsForPlan($plan)->values()->all();
            $data['prep_summary'] = $this->prepSummary($data['prep_assignments']);
        }

        if ($includeAssignables) {
            $data['assignable_chapters'] = $this->assignableSetsForPlan($plan);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignableSetsForPlan(ExamPlan $plan): array
    {
        $plan->loadMissing([
            'chapters:id,chapter_number,name,sort_order',
            'topics:id,syllabus_chapter_id,name',
        ]);

        $topicsByChapter = $plan->topics->groupBy('syllabus_chapter_id');

        $activeWorksheetIds = SetAssignment::query()
            ->where('student_enrollment_id', $plan->student_enrollment_id)
            ->whereNotIn('status', [SetAssignment::STATUS_CANCELLED])
            ->pluck('worksheet_id')
            ->all();

        return $plan->chapters->map(function (SyllabusChapter $chapter) use ($activeWorksheetIds, $topicsByChapter) {
            $partialTopicIds = $topicsByChapter->get($chapter->id)?->pluck('id')->all();

            $topicSets = Worksheet::query()
                ->where('status', Worksheet::STATUS_PUBLISHED)
                ->where('scope', PracticeSetScope::TOPIC)
                ->whereHas('topic', function ($query) use ($chapter, $partialTopicIds) {
                    $query->where('syllabus_chapter_id', $chapter->id);

                    if ($partialTopicIds) {
                        $query->whereIn('id', $partialTopicIds);
                    }
                })
                ->with('topic:id,name')
                ->withCount('questions')
                ->orderBy('set_number')
                ->get()
                ->map(fn (Worksheet $set) => [
                    'id' => $set->id,
                    'set_code' => $set->set_code,
                    'tier_label' => $set->tier_label,
                    'topic_name' => $set->topic?->name,
                    'questions_count' => $set->questions_count,
                    'kind_label' => 'Practice',
                    'is_assigned' => in_array($set->id, $activeWorksheetIds, true),
                ])
                ->values()
                ->all();

            $chapterTests = Worksheet::query()
                ->where('status', Worksheet::STATUS_PUBLISHED)
                ->where('scope', PracticeSetScope::CHAPTER)
                ->where('syllabus_chapter_id', $chapter->id)
                ->withCount('questions')
                ->orderBy('set_number')
                ->get()
                ->map(fn (Worksheet $set) => [
                    'id' => $set->id,
                    'set_code' => $set->set_code,
                    'tier_label' => $set->tier_label,
                    'topic_name' => null,
                    'questions_count' => $set->questions_count,
                    'kind_label' => 'Test',
                    'is_assigned' => in_array($set->id, $activeWorksheetIds, true),
                ])
                ->values()
                ->all();

            return [
                'chapter_id' => $chapter->id,
                'chapter_label' => self::chapterLabel($chapter),
                'topic_sets' => $topicSets,
                'chapter_tests' => $chapterTests,
            ];
        })->values()->all();
    }

    public static function chapterLabel(SyllabusChapter $chapter): string
    {
        $number = trim((string) $chapter->chapter_number);
        $prefix = str_starts_with(strtolower($number), 'ch') ? $number : "Ch {$number}";

        return "{$prefix} — {$chapter->name}";
    }

    /**
     * @param  Collection<int, SyllabusTopic>  $selectedTopics
     */
    public static function chapterSelectionLabel(SyllabusChapter $chapter, Collection $selectedTopics): string
    {
        $label = self::chapterLabel($chapter);

        if ($selectedTopics->isEmpty()) {
            return $label;
        }

        if ($selectedTopics->count() === 1) {
            return "{$label} · {$selectedTopics->first()->name}";
        }

        $names = $selectedTopics->take(2)->pluck('name')->join(', ');
        $extra = $selectedTopics->count() > 2 ? '…' : '';

        return "{$label} · {$selectedTopics->count()} topics ({$names}{$extra})";
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function prepAssignmentsForPlan(ExamPlan $plan): Collection
    {
        $plan->loadMissing('chapters:id');
        $chapterIds = $plan->chapters->pluck('id')->all();

        if ($chapterIds === []) {
            return collect();
        }

        return SetAssignment::query()
            ->with([
                'practiceSet' => fn ($q) => $q->withCount('questions')->with([
                    'topic:id,syllabus_chapter_id,name',
                    'chapter:id,name',
                ]),
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            ])
            ->where('student_enrollment_id', $plan->student_enrollment_id)
            ->whereNot('status', SetAssignment::STATUS_CANCELLED)
            ->where(function ($query) use ($plan, $chapterIds) {
                $query->where('exam_plan_id', $plan->id)
                    ->orWhere(function ($query) use ($plan, $chapterIds) {
                        $query->whereNull('exam_plan_id')
                            ->where('due_date', '<=', $plan->exam_date)
                            ->whereHas('practiceSet', function ($query) use ($chapterIds) {
                                $query->where(function ($query) use ($chapterIds) {
                                    $query->whereIn('syllabus_chapter_id', $chapterIds)
                                        ->orWhereHas('topic', fn ($query) => $query->whereIn('syllabus_chapter_id', $chapterIds));
                                });
                            });
                    });
            })
            ->orderBy('due_date')
            ->get()
            ->map(function (SetAssignment $assignment) {
                $latest = $assignment->attempts->first();
                $summary = AssignmentProgress::formatAssignmentSummary($assignment, $latest);

                return [
                    'assignment_id' => $assignment->id,
                    'practice_set_id' => $assignment->worksheet_id,
                    'set_code' => $summary['set_code'],
                    'kind_label' => $summary['kind_label'],
                    'target_date' => $summary['target_date'],
                    'status' => $summary['status'],
                    'assignment_status' => $summary['assignment_status'],
                    'is_overdue' => $summary['is_overdue'],
                    'latest_score' => $summary['latest_score'],
                    'latest_max_score' => $summary['latest_max_score'],
                    'submission_timing' => $summary['submission_timing'],
                    'progress_label' => $this->prepProgressLabel($summary),
                ];
            });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function prepProgressLabel(array $summary): string
    {
        if ($summary['assignment_status'] === SetAssignment::STATUS_COMPLETED && $summary['latest_score'] !== null) {
            $late = $summary['submission_timing'] === 'late' ? ' · late' : '';

            return "Done {$summary['latest_score']}/{$summary['latest_max_score']}{$late}";
        }

        if ($summary['is_overdue']) {
            return 'Overdue';
        }

        if ($summary['assignment_status'] === SetAssignment::STATUS_IN_PROGRESS) {
            return 'In progress';
        }

        return 'To do';
    }

    /**
     * @param  list<array<string, mixed>>  $prep
     * @return array{total: int, completed: int, pending: int}
     */
    private function prepSummary(array $prep): array
    {
        $completed = collect($prep)->where('assignment_status', SetAssignment::STATUS_COMPLETED)->count();

        return [
            'total' => count($prep),
            'completed' => $completed,
            'pending' => count($prep) - $completed,
        ];
    }

    public function matchingPlanForAssignment(
        StudentEnrollment $enrollment,
        Worksheet $worksheet,
        string $dueDate,
        ?int $examPlanId = null,
    ): ?ExamPlan {
        if ($examPlanId) {
            return ExamPlan::query()
                ->whereKey($examPlanId)
                ->where('student_enrollment_id', $enrollment->id)
                ->first();
        }

        $chapterId = $worksheet->isChapterScope()
            ? $worksheet->syllabus_chapter_id
            : $worksheet->topic?->syllabus_chapter_id;

        if (! $chapterId) {
            return null;
        }

        return ExamPlan::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('status', ExamPlan::STATUS_PLANNED)
            ->where('exam_date', '>=', $dueDate)
            ->whereHas('chapters', fn ($query) => $query->where('syllabus_chapters.id', $chapterId))
            ->orderBy('exam_date')
            ->first();
    }

    public function plansForEnrollment(StudentEnrollment $enrollment, bool $includeAssignables = false): Collection
    {
        return $enrollment->examPlans()
            ->with('chapters:id,chapter_number,name,sort_order')
            ->orderBy('exam_date')
            ->get()
            ->map(fn (ExamPlan $plan) => $this->formatPlan($plan, true, $includeAssignables));
    }

    public function examTypeOptions(): array
    {
        return [
            ['value' => ExamPlan::TYPE_UNIT_TEST, 'label' => 'Unit test'],
            ['value' => ExamPlan::TYPE_HALF_YEARLY, 'label' => 'Half yearly'],
            ['value' => ExamPlan::TYPE_FINAL, 'label' => 'Final exam'],
            ['value' => ExamPlan::TYPE_OTHER, 'label' => 'Other'],
        ];
    }

    /**
     * @return array{upcoming: Collection, past: Collection}
     */
    public function splitPlansByTiming(Collection $plans): array
    {
        $today = now()->toDateString();

        $upcoming = $plans->filter(fn (array $plan) => $plan['exam_date'] >= $today && $plan['status'] === ExamPlan::STATUS_PLANNED)
            ->values();

        $past = $plans->filter(fn (array $plan) => $plan['exam_date'] < $today || $plan['status'] === ExamPlan::STATUS_COMPLETED)
            ->sortByDesc('exam_date')
            ->values();

        return ['upcoming' => $upcoming, 'past' => $past];
    }

    public function activeEnrollmentForYear(int $academicYearId, int $gradeLevelId): Collection
    {
        return StudentEnrollment::query()
            ->with([
                'student:id,name',
                'examPlans' => fn ($q) => $q->with('chapters:id,chapter_number,name,sort_order')->orderBy('exam_date'),
            ])
            ->where('academic_year_id', $academicYearId)
            ->where('grade_level_id', $gradeLevelId)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->get()
            ->sortBy(fn (StudentEnrollment $enrollment) => $enrollment->student?->name ?? '')
            ->values();
    }

    /**
     * @param  'upcoming'|'past'|'all'  $filter
     */
    public function classHubRows(Collection $enrollments, string $filter = 'upcoming', bool $includeAssignables = false): array
    {
        $today = now()->toDateString();

        return $enrollments->map(function (StudentEnrollment $enrollment) use ($filter, $today, $includeAssignables) {
            $plans = $enrollment->examPlans->map(
                fn (ExamPlan $plan) => $this->formatPlan($plan, true, $includeAssignables),
            );

            $upcoming = $plans->filter(fn (array $plan) => $plan['exam_date'] >= $today && $plan['status'] === ExamPlan::STATUS_PLANNED)
                ->sortBy('exam_date')
                ->values();

            $past = $plans->filter(fn (array $plan) => $plan['exam_date'] < $today || $plan['status'] === ExamPlan::STATUS_COMPLETED)
                ->sortByDesc('exam_date')
                ->values();

            $displayPlan = match ($filter) {
                'past' => $past->first(),
                'all' => $upcoming->first() ?? $past->first(),
                default => $upcoming->first(),
            };

            return [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->name,
                'enrollment_id' => $enrollment->id,
                'has_plan' => $plans->isNotEmpty(),
                'has_upcoming' => $upcoming->isNotEmpty(),
                'upcoming_count' => $upcoming->count(),
                'display_plan' => $displayPlan,
                'all_plans' => $plans->values()->all(),
            ];
        })->values()->all();
    }
}
