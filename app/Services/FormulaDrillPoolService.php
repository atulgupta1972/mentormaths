<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\SyllabusTopic;
use App\Models\WrittenSubmission;
use App\Support\FormulaDrillSchema;
use App\Support\FormulaDrillScope;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Support\Collection;

class FormulaDrillPoolService
{
    public function __construct(
        private ExamPlanService $examPlanService,
    ) {}

    /**
     * @return list<int>
     */
    public function poolQuestionIds(Student $student): array
    {
        if (! FormulaDrillSchema::isReady()) {
            return [];
        }

        $topicIds = $this->completedTopicIdsForStudent($student);

        if ($topicIds === []) {
            return $this->globalBasicsQuestionIds();
        }

        $chapterQuestions = Question::query()
            ->where('bank_purpose', QuestionBankPurpose::FORMULA)
            ->whereIn('syllabus_topic_id', $topicIds)
            ->whereHas('options')
            ->pluck('id')
            ->all();

        $basics = $this->globalBasicsQuestionIds();

        return array_values(array_unique(array_merge($chapterQuestions, $basics)));
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
     * @return list<int>
     */
    private function completedTopicIdsForStudent(Student $student): array
    {
        $enrollments = $student->enrollments()
            ->with('gradeLevel:id,sort_order')
            ->get()
            ->sortBy(fn (StudentEnrollment $enrollment) => $enrollment->gradeLevel?->sort_order ?? 999);

        $topicIds = [];

        foreach ($enrollments as $enrollment) {
            $chapterIds = $this->completedChapterIdsForEnrollment($enrollment);

            if ($chapterIds === []) {
                continue;
            }

            $syllabusVersion = $this->examPlanService->syllabusVersionForEnrollment($enrollment);

            if (! $syllabusVersion) {
                continue;
            }

            $ids = SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $chapterIds)
                ->whereHas('chapter', fn ($query) => $query->where('syllabus_version_id', $syllabusVersion->id))
                ->pluck('id')
                ->all();

            $topicIds = array_merge($topicIds, $ids);
        }

        return array_values(array_unique($topicIds));
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
     * @return list<int>
     */
    private function globalBasicsQuestionIds(): array
    {
        return Question::query()
            ->where('bank_purpose', QuestionBankPurpose::FORMULA)
            ->where('formula_drill_scope', FormulaDrillScope::GLOBAL_BASICS)
            ->whereHas('options')
            ->pluck('id')
            ->all();
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
