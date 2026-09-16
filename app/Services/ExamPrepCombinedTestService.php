<?php

namespace App\Services;

use App\Models\ExamPlan;
use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetPurpose;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExamPrepCombinedTestService
{
    public const MAX_PER_SET = 25;

    public const MAX_SETS = 3;

    public function __construct(
        private SetAssignmentService $assignmentService,
        private PracticeSetService $practiceSetService,
    ) {}

    /**
     * Preview failure pool for an upcoming exam (prefer fill-blank).
     *
     * @return array{
     *     exam_plan_id: int,
     *     chapter_ids: list<int>,
     *     total_failures: int,
     *     fill_blank_count: int,
     *     mcq_count: int,
     *     already_used: int,
     *     available: int,
     *     suggested_sets: int,
     *     suggested_questions: int,
     *     existing_sets: list<array<string, mixed>>
     * }
     */
    public function preview(ExamPlan $plan): array
    {
        $plan->loadMissing(['chapters:id', 'enrollment.student']);
        $chapterIds = $plan->chapters->pluck('id')->map(fn ($id) => (int) $id)->all();
        $candidates = $this->candidateQuestions($plan);
        $existing = $this->existingSetsForPlan($plan);

        $fill = $candidates->where('type', Question::TYPE_FILL_IN_BLANK)->count();
        $mcq = $candidates->where('type', Question::TYPE_MCQ)->count();
        $available = $candidates->count();
        $suggestedQuestions = min($available, self::MAX_PER_SET * self::MAX_SETS);
        $suggestedSets = $suggestedQuestions === 0
            ? 0
            : (int) ceil($suggestedQuestions / self::MAX_PER_SET);

        return [
            'exam_plan_id' => $plan->id,
            'chapter_ids' => $chapterIds,
            'total_failures' => $available,
            'fill_blank_count' => $fill,
            'mcq_count' => $mcq,
            'already_used' => $this->alreadyUsedQuestionIds($plan)->count(),
            'available' => $available,
            'suggested_sets' => $suggestedSets,
            'suggested_questions' => $suggestedQuestions,
            'existing_sets' => $existing,
        ];
    }

    /**
     * Build draft exam-prep worksheets (not assigned until approved).
     *
     * @return list<array<string, mixed>>
     */
    public function generateDrafts(ExamPlan $plan, User $creator): array
    {
        $plan->loadMissing(['chapters:id,chapter_number,name', 'enrollment.student', 'enrollment']);
        $enrollment = $plan->enrollment;
        if (! $enrollment instanceof StudentEnrollment) {
            throw new InvalidArgumentException('Exam plan has no enrollment.');
        }

        $candidates = $this->candidateQuestions($plan);
        if ($candidates->isEmpty()) {
            throw new InvalidArgumentException(
                'No pending wrong sums found for this exam’s chapters. Student needs practice/test failures on those chapters first.',
            );
        }

        $picked = $candidates->take(self::MAX_PER_SET * self::MAX_SETS)->values();
        $chunks = $picked->chunk(self::MAX_PER_SET)->values();

        $created = [];

        DB::transaction(function () use ($chunks, $plan, $enrollment, $creator, &$created) {
            $part = 1;
            foreach ($chunks as $chunk) {
                $created[] = $this->createDraftWorksheet(
                    $plan,
                    $enrollment,
                    $creator,
                    $chunk->values(),
                    $part,
                    $chunks->count(),
                );
                $part++;
            }
        });

        return $created;
    }

    /**
     * Publish a draft exam-prep set and assign it to the student under the exam plan.
     *
     * @return array{worksheet_id: int, assignment_id: int, set_code: string}
     */
    public function approveAndAssign(Worksheet $worksheet, User $assigner): array
    {
        if (! $worksheet->isExamPrep()) {
            throw new InvalidArgumentException('This is not an exam-prep set.');
        }

        if ($worksheet->status === Worksheet::STATUS_PUBLISHED) {
            $existing = SetAssignment::query()
                ->where('worksheet_id', $worksheet->id)
                ->where('student_enrollment_id', $worksheet->catch_up_for_enrollment_id)
                ->whereNot('status', SetAssignment::STATUS_CANCELLED)
                ->first();
            if ($existing) {
                return [
                    'worksheet_id' => $worksheet->id,
                    'assignment_id' => $existing->id,
                    'set_code' => $worksheet->set_code,
                ];
            }
        }

        $plan = ExamPlan::query()->with('enrollment.student')->find($worksheet->exam_plan_id);
        if (! $plan) {
            throw new InvalidArgumentException('Exam plan missing for this set.');
        }

        $enrollment = $plan->enrollment;
        if (! $enrollment) {
            throw new InvalidArgumentException('Enrollment missing for this exam plan.');
        }

        $due = $plan->exam_date?->copy()->subDay()->toDateString()
            ?? now()->addDays(3)->toDateString();

        $worksheet->update(['status' => Worksheet::STATUS_PUBLISHED]);

        $assignment = $this->assignmentService->assign(
            $worksheet->fresh(),
            $enrollment,
            $assigner,
            $due,
            'Exam prep — '.$plan->title,
            $plan->id,
        );

        return [
            'worksheet_id' => $worksheet->id,
            'assignment_id' => $assignment->id,
            'set_code' => $worksheet->set_code,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function existingSetsForPlan(ExamPlan $plan): array
    {
        return Worksheet::query()
            ->withCount('questions')
            ->where('purpose', WorksheetPurpose::EXAM_PREP)
            ->where('exam_plan_id', $plan->id)
            ->orderBy('id')
            ->get()
            ->map(function (Worksheet $ws) {
                $assignment = SetAssignment::query()
                    ->where('worksheet_id', $ws->id)
                    ->whereNot('status', SetAssignment::STATUS_CANCELLED)
                    ->first();

                return [
                    'id' => $ws->id,
                    'set_code' => $ws->set_code,
                    'title' => $ws->title,
                    'status' => $ws->status,
                    'questions_count' => $ws->questions_count,
                    'assignment_id' => $assignment?->id,
                    'assignment_status' => $assignment?->status,
                    'review_url' => route('admin.practice-sets.show', $ws),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Failed questions for exam chapters: fill-blank first, then MCQ.
     *
     * @return Collection<int, array{question_id: int, type: string, topic_id: ?int, chapter_id: ?int}>
     */
    public function candidateQuestions(ExamPlan $plan): Collection
    {
        $plan->loadMissing(['chapters:id', 'enrollment']);
        $studentId = (int) ($plan->enrollment?->student_id ?? 0);
        $chapterIds = $plan->chapters->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($studentId <= 0 || $chapterIds === []) {
            return collect();
        }

        $used = $this->alreadyUsedQuestionIds($plan);

        $items = PracticeCorrectionItem::query()
            ->with(['question:id,type,syllabus_topic_id', 'question.topic:id,syllabus_chapter_id'])
            ->where('student_id', $studentId)
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->whereIn('syllabus_chapter_id', $chapterIds)
            ->orderBy('first_failure_at')
            ->get();

        $rows = [];
        $seen = [];

        foreach ($items as $item) {
            $question = $item->question;
            if (! $question) {
                continue;
            }
            $qid = (int) $question->id;
            if (isset($seen[$qid]) || $used->contains($qid)) {
                continue;
            }
            $seen[$qid] = true;
            $type = $question->type === Question::TYPE_FILL_IN_BLANK
                ? Question::TYPE_FILL_IN_BLANK
                : Question::TYPE_MCQ;

            $rows[] = [
                'question_id' => $qid,
                'type' => $type,
                'topic_id' => $question->syllabus_topic_id ? (int) $question->syllabus_topic_id : null,
                'chapter_id' => $item->syllabus_chapter_id ? (int) $item->syllabus_chapter_id : null,
                'failed_at' => $item->first_failure_at?->timestamp ?? 0,
            ];
        }

        return collect($rows)
            ->sort(function (array $left, array $right): int {
                $leftFill = $left['type'] === Question::TYPE_FILL_IN_BLANK ? 0 : 1;
                $rightFill = $right['type'] === Question::TYPE_FILL_IN_BLANK ? 0 : 1;
                if ($leftFill !== $rightFill) {
                    return $leftFill <=> $rightFill;
                }

                return $left['failed_at'] <=> $right['failed_at'];
            })
            ->values();
    }

    /**
     * @return Collection<int, int>
     */
    private function alreadyUsedQuestionIds(ExamPlan $plan): Collection
    {
        return Worksheet::query()
            ->where('purpose', WorksheetPurpose::EXAM_PREP)
            ->where('exam_plan_id', $plan->id)
            ->get(['catch_up_source_question_ids'])
            ->flatMap(fn (Worksheet $w) => $w->catch_up_source_question_ids ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, array{question_id: int, type: string, topic_id: ?int, chapter_id: ?int}>  $chunk
     * @return array<string, mixed>
     */
    private function createDraftWorksheet(
        ExamPlan $plan,
        StudentEnrollment $enrollment,
        User $creator,
        Collection $chunk,
        int $part,
        int $totalParts,
    ): array {
        $primaryChapterId = $chunk
            ->pluck('chapter_id')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $primaryTopicId = $chunk
            ->pluck('topic_id')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $fillCount = $chunk->where('type', Question::TYPE_FILL_IN_BLANK)->count();
        $mcqCount = $chunk->where('type', Question::TYPE_MCQ)->count();
        $studentName = $enrollment->student?->name ?? 'Student';
        $setCode = $this->nextExamPrepCode($plan, $part);
        $setNumber = $primaryChapterId
            ? $this->practiceSetService->nextChapterSetNumber((int) $primaryChapterId)
            : ($primaryTopicId
                ? $this->practiceSetService->nextSetNumber((int) $primaryTopicId)
                : ((int) Worksheet::query()->max('set_number') + 1));

        $partLabel = $totalParts > 1 ? " (part {$part}/{$totalParts})" : '';

        $worksheet = Worksheet::query()->create([
            'title' => "{$setCode} — Exam prep for {$studentName}{$partLabel}",
            'set_number' => $setNumber,
            'set_code' => $setCode,
            'tier' => PracticeSetTier::STARTER,
            'scope' => $primaryChapterId ? PracticeSetScope::CHAPTER : PracticeSetScope::TOPIC,
            'syllabus_chapter_id' => $primaryChapterId,
            'syllabus_topic_id' => $primaryChapterId ? null : $primaryTopicId,
            'status' => Worksheet::STATUS_DRAFT,
            'notes' => sprintf(
                'Exam prep for plan #%d (%s). %d fill-blank + %d MCQ from student wrongs. Review then Approve to assign.',
                $plan->id,
                $plan->title,
                $fillCount,
                $mcqCount,
            ),
            'created_by' => $creator->id,
            'purpose' => WorksheetPurpose::EXAM_PREP,
            'exam_plan_id' => $plan->id,
            'catch_up_for_enrollment_id' => $enrollment->id,
            'catch_up_source_question_ids' => $chunk->pluck('question_id')->values()->all(),
        ]);

        foreach ($chunk->values() as $index => $row) {
            $worksheet->questions()->attach($row['question_id'], ['sort_order' => $index + 1]);
        }

        return [
            'id' => $worksheet->id,
            'set_code' => $setCode,
            'status' => Worksheet::STATUS_DRAFT,
            'questions_count' => $chunk->count(),
            'fill_blank_count' => $fillCount,
            'mcq_count' => $mcqCount,
            'review_url' => route('admin.practice-sets.show', $worksheet),
        ];
    }

    private function nextExamPrepCode(ExamPlan $plan, int $part): string
    {
        $prefix = 'EP'.$plan->id;
        $existing = Worksheet::query()
            ->where('purpose', WorksheetPurpose::EXAM_PREP)
            ->where('exam_plan_id', $plan->id)
            ->count();

        return sprintf('%s-%d', $prefix, $existing + $part);
    }
}
