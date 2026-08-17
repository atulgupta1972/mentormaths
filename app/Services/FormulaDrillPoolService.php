<?php

namespace App\Services;

use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\WrittenSubmission;
use App\Support\FormulaDrillSchema;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Support\Collection;

class FormulaDrillPoolService
{
    public const SHARED_FORMULA_BOARD_CODE = 'CBSE';

    public function __construct(
        private ExamPlanService $examPlanService,
    ) {}

    /**
     * Formula inventory: every formula from the previous grade syllabus, plus every
     * formula from the student's current class syllabus (same board / year).
     *
     * If the student's board is not CBSE and that board has no current-class formulas
     * yet (ICSE / SBSE course still being uploaded), use CBSE formulas for the
     * previous class and current class instead.
     *
     * @return list<int>
     */
    public function poolQuestionIds(Student $student): array
    {
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return [];
        }

        return $this->poolQuestionIdsForEnrollment($enrollment);
    }

    /**
     * @return list<int>
     */
    public function poolQuestionIdsForEnrollment(StudentEnrollment $enrollment): array
    {
        if (! FormulaDrillSchema::isReady()) {
            return [];
        }

        $parts = $this->poolPartsForEnrollment($enrollment);

        return $this->mergePools($parts['previous_ids'], $parts['current_ids']);
    }

    /**
     * Formula pool for coverage: id plus chapter label, in syllabus order.
     *
     * @return list<array{id: int, chapter_label: string, sort: int}>
     */
    public function poolCatalogForEnrollment(StudentEnrollment $enrollment): array
    {
        $ids = $this->poolQuestionIdsForEnrollment($enrollment);

        if ($ids === []) {
            return [];
        }

        return Question::query()
            ->whereIn('id', $ids)
            ->with(['topic.chapter:id,name,chapter_number,sort_order'])
            ->get()
            ->map(function (Question $question) {
                $chapter = $question->topic?->chapter;
                $name = $chapter?->name ?: 'Formulas';
                $number = trim((string) ($chapter?->chapter_number ?? ''));
                $label = $number !== '' ? "Ch {$number} {$name}" : $name;

                return [
                    'id' => (int) $question->id,
                    'chapter_label' => $label,
                    'sort' => (int) ($chapter?->sort_order ?? 999),
                ];
            })
            ->sortBy([
                ['sort', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all();
    }

    public function poolSize(Student $student): int
    {
        return count($this->poolQuestionIds($student));
    }

    /**
     * @return array{
     *     previous_grade_name: ?string,
     *     previous_grade_count: int,
     *     current_grade_count: int,
     *     total: int,
     *     using_cbse_fallback: bool
     * }
     */
    public function poolBreakdown(Student $student): array
    {
        $empty = [
            'previous_grade_name' => null,
            'previous_grade_count' => 0,
            'current_grade_count' => 0,
            'total' => 0,
            'using_cbse_fallback' => false,
        ];

        if (! FormulaDrillSchema::isReady()) {
            return $empty;
        }

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return $empty;
        }

        $parts = $this->poolPartsForEnrollment($enrollment);
        $previousGrade = $this->resolvePreviousGrade($enrollment);

        return [
            'previous_grade_name' => $previousGrade?->name,
            'previous_grade_count' => count($parts['previous_ids']),
            'current_grade_count' => count(array_diff($parts['current_ids'], $parts['previous_ids'])),
            'total' => count($this->mergePools($parts['previous_ids'], $parts['current_ids'])),
            'using_cbse_fallback' => $parts['using_cbse_fallback'],
        ];
    }

    /**
     * @return list<int>
     */
    public function completedChapterIdsForEnrollment(StudentEnrollment $enrollment): array
    {
        $assignments = SetAssignment::query()
            ->with(['practiceSet:id,syllabus_topic_id,syllabus_chapter_id,delivery_mode'])
            ->where('student_enrollment_id', $enrollment->id)
            ->whereNot('status', SetAssignment::STATUS_CANCELLED)
            ->get();

        $chapterIds = [];

        foreach ($assignments as $assignment) {
            if (! $this->isAssignmentCompleted($assignment)) {
                continue;
            }

            $chapterId = $this->resolveChapterId($assignment);

            if ($chapterId) {
                $chapterIds[] = $chapterId;
            }
        }

        return array_values(array_unique($chapterIds));
    }

    /**
     * @return array{previous_ids: list<int>, current_ids: list<int>, using_cbse_fallback: bool}
     */
    private function poolPartsForEnrollment(StudentEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['gradeLevel', 'board']);

        $currentIds = $this->formulaIdsForTopicIds($this->currentGradeTopicIds($enrollment));
        $previousIds = $this->formulaIdsForTopicIds($this->previousGradeTopicIds($enrollment));

        if ($currentIds !== [] || ! $this->shouldFallBackToCbseFormulas($enrollment)) {
            return [
                'previous_ids' => $previousIds,
                'current_ids' => $currentIds,
                'using_cbse_fallback' => false,
            ];
        }

        $cbseBoardId = $this->sharedFormulaBoardId();

        if (! $cbseBoardId) {
            return [
                'previous_ids' => $previousIds,
                'current_ids' => $currentIds,
                'using_cbse_fallback' => false,
            ];
        }

        $currentIds = $this->formulaIdsForTopicIds(
            $this->topicIdsForGradeOnBoard($enrollment, (int) $enrollment->grade_level_id, $cbseBoardId)
        );
        $previousGrade = $this->resolvePreviousGrade($enrollment);
        $previousIds = $previousGrade
            ? $this->formulaIdsForTopicIds(
                $this->topicIdsForGradeOnBoard($enrollment, (int) $previousGrade->id, $cbseBoardId)
            )
            : [];

        return [
            'previous_ids' => $previousIds,
            'current_ids' => $currentIds,
            'using_cbse_fallback' => $currentIds !== [] || $previousIds !== [],
        ];
    }

    private function shouldFallBackToCbseFormulas(StudentEnrollment $enrollment): bool
    {
        $code = strtoupper(trim((string) ($enrollment->board?->code ?? '')));

        return $code !== '' && $code !== self::SHARED_FORMULA_BOARD_CODE;
    }

    private function sharedFormulaBoardId(): ?int
    {
        $id = Board::query()
            ->where('code', self::SHARED_FORMULA_BOARD_CODE)
            ->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @return list<int>
     */
    private function topicIdsForGradeOnBoard(StudentEnrollment $enrollment, int $gradeLevelId, int $boardId): array
    {
        $mathsSubjectId = Subject::query()->where('code', 'MATHS')->value('id');

        if (! $mathsSubjectId) {
            return [];
        }

        $syllabusVersion = SyllabusVersion::query()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('grade_level_id', $gradeLevelId)
            ->where('board_id', $boardId)
            ->where('subject_id', $mathsSubjectId)
            ->first();

        if (! $syllabusVersion) {
            return [];
        }

        return SyllabusTopic::query()
            ->whereHas('chapter', fn ($query) => $query->where('syllabus_version_id', $syllabusVersion->id))
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<int>  $topicIds
     * @return list<int>
     */
    private function formulaIdsForTopicIds(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        return Question::query()
            ->where('bank_purpose', QuestionBankPurpose::FORMULA)
            ->whereIn('syllabus_topic_id', $topicIds)
            ->whereHas('options')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  list<list<int>>  $pools
     * @return list<int>
     */
    private function mergePools(array ...$pools): array
    {
        $merged = [];

        foreach ($pools as $pool) {
            $merged = array_merge($merged, $pool);
        }

        return array_values(array_unique($merged));
    }

    /**
     * Current-grade pool: every formula topic on the student's current class syllabus.
     *
     * @return list<int>
     */
    private function currentGradeTopicIds(StudentEnrollment $enrollment): array
    {
        $syllabusVersion = $this->examPlanService->syllabusVersionForEnrollment($enrollment);

        if (! $syllabusVersion) {
            return [];
        }

        return SyllabusTopic::query()
            ->whereHas('chapter', fn ($query) => $query->where('syllabus_version_id', $syllabusVersion->id))
            ->pluck('id')
            ->all();
    }

    /**
     * Previous-grade pool: every formula from the immediately lower class syllabus
     * (Class 9 → all Class 8 topics, same board and academic year).
     *
     * @return list<int>
     */
    private function previousGradeTopicIds(StudentEnrollment $enrollment): array
    {
        $previousGrade = $this->resolvePreviousGrade($enrollment);

        if (! $previousGrade) {
            return [];
        }

        return $this->topicIdsForGradeOnBoard($enrollment, (int) $previousGrade->id, (int) $enrollment->board_id);
    }

    private function resolvePreviousGrade(StudentEnrollment $enrollment): ?GradeLevel
    {
        $sortOrder = $enrollment->gradeLevel?->sort_order;

        if ($sortOrder === null || $sortOrder <= 1) {
            return null;
        }

        return GradeLevel::query()
            ->where('is_active', true)
            ->where('sort_order', '<', $sortOrder)
            ->orderByDesc('sort_order')
            ->first();
    }

    /**
     * @return list<int>
     */
    private function completedTopicIdsForEnrollment(StudentEnrollment $enrollment): array
    {
        $chapterIds = $this->completedChapterIdsForEnrollment($enrollment);

        if ($chapterIds === []) {
            return [];
        }

        return $this->topicIdsForChapters($enrollment, $chapterIds);
    }

    /**
     * @return list<int>
     */
    private function assignedTopicIdsForEnrollment(StudentEnrollment $enrollment): array
    {
        $assignments = SetAssignment::query()
            ->with(['practiceSet:id,syllabus_topic_id,syllabus_chapter_id'])
            ->where('student_enrollment_id', $enrollment->id)
            ->whereNot('status', SetAssignment::STATUS_CANCELLED)
            ->get();

        $chapterIds = [];

        foreach ($assignments as $assignment) {
            $chapterId = $this->resolveChapterId($assignment);

            if ($chapterId) {
                $chapterIds[] = $chapterId;
            }
        }

        $chapterIds = array_values(array_unique($chapterIds));

        if ($chapterIds === []) {
            return [];
        }

        return $this->topicIdsForChapters($enrollment, $chapterIds);
    }

    /**
     * @param  list<int>  $chapterIds
     * @return list<int>
     */
    private function topicIdsForChapters(StudentEnrollment $enrollment, array $chapterIds): array
    {
        $syllabusVersion = $this->examPlanService->syllabusVersionForEnrollment($enrollment);

        if (! $syllabusVersion) {
            return [];
        }

        return SyllabusTopic::query()
            ->whereIn('syllabus_chapter_id', $chapterIds)
            ->whereHas('chapter', fn ($query) => $query->where('syllabus_version_id', $syllabusVersion->id))
            ->pluck('id')
            ->all();
    }

    private function isAssignmentCompleted(SetAssignment $assignment): bool
    {
        $worksheet = $assignment->practiceSet;

        if (! $worksheet || $worksheet->isFormula()) {
            return false;
        }

        if ($assignment->status === SetAssignment::STATUS_COMPLETED) {
            return true;
        }

        if (($worksheet->delivery_mode ?? WorksheetDeliveryMode::ONLINE) === WorksheetDeliveryMode::WRITTEN) {
            return $assignment->writtenSubmissions()
                ->where('status', WrittenSubmission::STATUS_GRADED)
                ->exists();
        }

        return $assignment->attempts()
            ->where('status', SetAttempt::STATUS_SUBMITTED)
            ->exists();
    }

    private function resolveChapterId(SetAssignment $assignment): ?int
    {
        $worksheet = $assignment->practiceSet;

        if (! $worksheet) {
            return null;
        }

        if ($worksheet->syllabus_chapter_id) {
            return (int) $worksheet->syllabus_chapter_id;
        }

        if ($worksheet->syllabus_topic_id) {
            return SyllabusTopic::query()
                ->whereKey($worksheet->syllabus_topic_id)
                ->value('syllabus_chapter_id');
        }

        return null;
    }

    /**
     * @return Collection<int, Question>
     */
    public function questionsByIds(array $questionIds): Collection
    {
        if ($questionIds === []) {
            return collect();
        }

        return Question::query()
            ->with(['options' => fn ($query) => $query->orderBy('sort_order')])
            ->whereIn('id', $questionIds)
            ->get()
            ->keyBy('id');
    }
}
