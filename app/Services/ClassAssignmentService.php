<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;

class ClassAssignmentService
{
    public function __construct(private SetAssignmentService $setAssignmentService) {}

    public function syllabusForGrade(GradeLevel $gradeLevel): ?SyllabusVersion
    {
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $activeYear || ! $maths) {
            return null;
        }

        return SyllabusVersion::query()
            ->with(['board:id,code,name'])
            ->where('academic_year_id', $activeYear->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->where('subject_id', $maths->id)
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignableChapters(SyllabusVersion $syllabusVersion): array
    {
        $chapters = SyllabusChapter::query()
            ->where('syllabus_version_id', $syllabusVersion->id)
            ->orderBy('sort_order')
            ->get(['id', 'chapter_number', 'name', 'sort_order']);

        return $chapters->map(function (SyllabusChapter $chapter) {
            $topicSets = Worksheet::query()
                ->where('status', Worksheet::STATUS_PUBLISHED)
                ->where('scope', PracticeSetScope::TOPIC)
                ->whereHas('topic', fn ($query) => $query->where('syllabus_chapter_id', $chapter->id))
                ->with('topic:id,name')
                ->withCount('questions')
                ->orderBy('set_number')
                ->get()
                ->map(fn (Worksheet $set) => $this->worksheetSummary($set, 'Practice'))
                ->values()
                ->all();

            $chapterTests = Worksheet::query()
                ->where('status', Worksheet::STATUS_PUBLISHED)
                ->where('scope', PracticeSetScope::CHAPTER)
                ->where('syllabus_chapter_id', $chapter->id)
                ->withCount('questions')
                ->orderBy('set_number')
                ->get()
                ->map(fn (Worksheet $set) => $this->worksheetSummary($set, 'Test'))
                ->values()
                ->all();

            return [
                'chapter_id' => $chapter->id,
                'chapter_label' => ExamPlanService::chapterLabel($chapter),
                'topic_sets' => $topicSets,
                'chapter_tests' => $chapterTests,
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>  $worksheetIds
     * @return array{
     *     assigned: int,
     *     skipped: int,
     *     errors: list<string>,
     *     worksheets_assigned: int,
     *     students_notified: list<Student>,
     *     worksheets_by_student: array<int, list<Worksheet>>
     * }
     */
    public function assignWorksheetsToClass(
        GradeLevel $gradeLevel,
        array $worksheetIds,
        User $assigner,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            throw new \InvalidArgumentException('No active academic year.');
        }

        $worksheetIds = array_values(array_unique(array_map('intval', $worksheetIds)));

        $worksheets = Worksheet::query()
            ->whereIn('id', $worksheetIds)
            ->where('status', Worksheet::STATUS_PUBLISHED)
            ->with(['topic:id,name,syllabus_chapter_id', 'chapter:id,name'])
            ->withCount('questions')
            ->get()
            ->keyBy('id');

        if ($worksheets->count() !== count($worksheetIds)) {
            throw new \InvalidArgumentException('One or more selected sheets are missing or not published.');
        }

        $enrollments = StudentEnrollment::query()
            ->with('student')
            ->where('academic_year_id', $activeYear->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->get();

        if ($enrollments->isEmpty()) {
            throw new \InvalidArgumentException('No active students in this class for the current year.');
        }

        $assigned = 0;
        $skipped = 0;
        $errors = [];
        $worksheetsByStudent = [];

        foreach ($worksheetIds as $worksheetId) {
            $worksheet = $worksheets->get($worksheetId);

            if (! $worksheet) {
                continue;
            }

            $result = $this->setAssignmentService->assignBulk(
                $worksheet,
                $enrollments,
                $assigner,
                $dueDate,
                $notes,
            );

            $assigned += $result['assigned'];
            $skipped += $result['skipped'];
            array_push($errors, ...$result['errors']);

            foreach ($result['assignedStudents'] ?? [] as $student) {
                $worksheetsByStudent[$student->id]['student'] = $student;
                $worksheetsByStudent[$student->id]['worksheets'][] = $worksheet;
            }
        }

        $studentsNotified = collect($worksheetsByStudent)
            ->map(fn (array $row) => $row['student'])
            ->values()
            ->all();

        return [
            'assigned' => $assigned,
            'skipped' => $skipped,
            'errors' => $errors,
            'worksheets_assigned' => count($worksheetIds),
            'students_notified' => $studentsNotified,
            'worksheets_by_student' => collect($worksheetsByStudent)
                ->mapWithKeys(fn (array $row, int $studentId) => [$studentId => $row['worksheets']])
                ->all(),
        ];
    }

    public function activeStudentsCount(GradeLevel $gradeLevel): int
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return 0;
        }

        return StudentEnrollment::query()
            ->where('academic_year_id', $activeYear->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function worksheetSummary(Worksheet $set, string $kindLabel): array
    {
        return [
            'id' => $set->id,
            'set_code' => $set->set_code,
            'tier_label' => $set->tier_label,
            'topic_name' => $set->topic?->name,
            'questions_count' => $set->questions_count,
            'kind_label' => $kindLabel,
        ];
    }
}
