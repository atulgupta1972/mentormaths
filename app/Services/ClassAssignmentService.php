<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\AssignmentProgress;
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
     * Chapter-wise practice/test sets with per-student status for a class.
     *
     * @return array{students: list<array<string, mixed>>, chapters: list<array<string, mixed>>}
     */
    public function classSetStatusBoard(GradeLevel $gradeLevel, ?int $chapterId = null): array
    {
        $syllabusVersion = $this->syllabusForGrade($gradeLevel);

        if (! $syllabusVersion) {
            return ['students' => [], 'chapters' => []];
        }

        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return ['students' => [], 'chapters' => []];
        }

        $enrollments = StudentEnrollment::query()
            ->with('student:id,name')
            ->where('academic_year_id', $activeYear->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->whereHas('student')
            ->get()
            ->sortBy(fn (StudentEnrollment $enrollment) => $enrollment->student->name)
            ->values();

        $students = $enrollments->map(fn (StudentEnrollment $enrollment) => [
            'id' => $enrollment->student_id,
            'enrollment_id' => $enrollment->id,
            'name' => $enrollment->student->name,
            'label' => $enrollment->student->name,
        ])->values()->all();

        $chapters = collect($this->assignableChapters($syllabusVersion));

        if ($chapterId) {
            $chapters = $chapters->where('chapter_id', $chapterId)->values();
        }

        $worksheetIds = $chapters
            ->flatMap(function (array $chapter) {
                return collect($chapter['topic_sets'] ?? [])
                    ->pluck('id')
                    ->merge(collect($chapter['chapter_tests'] ?? [])->pluck('id'));
            })
            ->unique()
            ->values();

        $assignmentsByKey = $worksheetIds->isEmpty()
            ? collect()
            : SetAssignment::query()
                ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
                ->whereIn('worksheet_id', $worksheetIds)
                ->with([
                    'practiceSet' => fn ($query) => $query->withCount('questions'),
                    'attempts' => fn ($query) => $query->orderByDesc('attempt_number'),
                ])
                ->get()
                ->keyBy(fn (SetAssignment $assignment) => "{$assignment->worksheet_id}:{$assignment->student_enrollment_id}");

        $chapterRows = $chapters->map(function (array $chapter) use ($enrollments, $assignmentsByKey) {
            $sets = [];

            foreach (array_merge($chapter['topic_sets'] ?? [], $chapter['chapter_tests'] ?? []) as $set) {
                $studentRows = $enrollments->map(function (StudentEnrollment $enrollment) use ($set, $assignmentsByKey) {
                    /** @var SetAssignment|null $assignment */
                    $assignment = $assignmentsByKey->get("{$set['id']}:{$enrollment->id}");
                    $latest = $assignment?->attempts->first();
                    $progress = $assignment
                        ? AssignmentProgress::formatAssignmentSummary($assignment, $latest)
                        : null;

                    return [
                        'student_id' => $enrollment->student_id,
                        'student_name' => $enrollment->student->name,
                        'progress' => $progress,
                    ];
                })->values()->all();

                $sets[] = [
                    ...$set,
                    'chapter_label' => $chapter['chapter_label'],
                    'students' => $studentRows,
                    'assigned_count' => collect($studentRows)->filter(fn (array $row) => $row['progress'] !== null)->count(),
                    'completed_count' => collect($studentRows)->filter(
                        fn (array $row) => ($row['progress']['assignment_status'] ?? null) === SetAssignment::STATUS_COMPLETED,
                    )->count(),
                ];
            }

            return [
                'chapter_id' => $chapter['chapter_id'],
                'chapter_label' => $chapter['chapter_label'],
                'sets' => $sets,
            ];
        })->values()->all();

        return [
            'students' => $students,
            'chapters' => $chapterRows,
        ];
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
            'scope' => $set->scope ?? PracticeSetScope::TOPIC,
        ];
    }
}
