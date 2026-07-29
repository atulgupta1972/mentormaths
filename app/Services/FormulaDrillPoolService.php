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
     * Formula inventory: all formulas from the previous grade syllabus, plus formulas
     * from topics the student has completed or been assigned in their current enrollment.
     *
     * @return list<int>
     */
    public function poolQuestionIds(Student $student): array
    {
        if (! FormulaDrillSchema::isReady()) {
            return [];
        }

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return [];
        }

        $enrollment->loadMissing('gradeLevel');

        $previousGradeTopicIds = $this->previousGradeTopicIds($enrollment);
        $completedTopicIds = $this->completedTopicIdsForEnrollment($enrollment);
        $assignedTopicIds = $this->assignedTopicIdsForEnrollment($enrollment);

        $currentGradeTopicIds = array_values(array_unique(array_merge(
            $completedTopicIds,
            $assignedTopicIds,
        )));

        return $this->mergePools(
            $this->formulaIdsForTopicIds($previousGradeTopicIds),
            $this->formulaIdsForTopicIds($currentGradeTopicIds),
        );
    }

    public function poolSize(Student $student): int
    {
        return count($this->poolQuestionIds($student));
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
     * @return list<int>
     */
    private function previousGradeTopicIds(StudentEnrollment $enrollment): array
    {
        $sortOrder = $enrollment->gradeLevel?->sort_order;

        if ($sortOrder === null || $sortOrder <= 1) {
            return [];
        }

        $previousGrade = GradeLevel::query()
            ->where('is_active', true)
            ->where('sort_order', $sortOrder - 1)
            ->first();

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
