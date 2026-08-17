<?php

namespace App\Services;

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
    public function __construct(
        private ExamPlanService $examPlanService,
    ) {}

    /**
     * Formula inventory: every formula from the previous grade syllabus, plus every
     * formula from the student's current class syllabus (same board / year).
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

        $enrollment->loadMissing('gradeLevel');

        return $this->mergePools(
            $this->formulaIdsForTopicIds($this->previousGradeTopicIds($enrollment)),
            $this->formulaIdsForTopicIds($this->currentGradeTopicIds($enrollment)),
        );
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
     *     total: int
     * }
     */
    public function poolBreakdown(Student $student): array
    {
        if (! FormulaDrillSchema::isReady()) {
            return [
                'previous_grade_name' => null,
                'previous_grade_count' => 0,
                'current_grade_count' => 0,
                'total' => 0,
            ];
        }

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return [
                'previous_grade_name' => null,
                'previous_grade_count' => 0,
                'current_grade_count' => 0,
                'total' => 0,
            ];
        }

        $enrollment->loadMissing('gradeLevel');

        $previousIds = $this->formulaIdsForTopicIds($this->previousGradeTopicIds($enrollment));
        $currentIds = $this->formulaIdsForTopicIds($this->currentGradeTopicIds($enrollment));
        $previousGrade = $this->resolvePreviousGrade($enrollment);

        return [
            'previous_grade_name' => $previousGrade?->name,
            'previous_grade_count' => count($previousIds),
            'current_grade_count' => count(array_diff($currentIds, $previousIds)),
            'total' => count($this->mergePools($previousIds, $currentIds)),
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

        $mathsSubjectId = Subject::query()->where('code', 'MATHS')->value('id');

        if (! $mathsSubjectId) {
            return [];
        }

        $syllabusVersion = SyllabusVersion::query()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('grade_level_id', $previousGrade->id)
            ->where('board_id', $enrollment->board_id)
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
