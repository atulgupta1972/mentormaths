<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusVersion;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class MentorClassHubService
{
    public function __construct(
        private StudentMentorService $mentorService,
        private ExamPlanService $examPlanService,
        private ClassHubProgressService $progress,
        private AdminGradeContext $gradeContext,
    ) {}

    /**
     * Grade-level cards (same layout as admin Classes) with counts of this mentor's students only.
     *
     * @return list<array<string, mixed>>
     */
    public function classCards(User $mentor): array
    {
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();
        $mentorStudentIds = $this->mentorService->studentIdsForUser($mentor);

        return $this->gradeContext->classLevels()->map(function (GradeLevel $grade) use ($activeYear, $maths, $mentorStudentIds) {
            $syllabus = null;

            if ($activeYear && $maths) {
                $syllabus = SyllabusVersion::query()
                    ->where('academic_year_id', $activeYear->id)
                    ->where('grade_level_id', $grade->id)
                    ->where('subject_id', $maths->id)
                    ->withCount('chapters')
                    ->first();
            }

            $studentsCount = 0;
            if ($activeYear && $mentorStudentIds !== []) {
                $studentsCount = StudentEnrollment::query()
                    ->where('academic_year_id', $activeYear->id)
                    ->where('grade_level_id', $grade->id)
                    ->where('status', StudentEnrollment::STATUS_ACTIVE)
                    ->whereIn('student_id', $mentorStudentIds)
                    ->count();
            }

            return [
                'id' => $grade->id,
                'name' => $grade->name,
                'sort_order' => $grade->sort_order,
                'chapters_count' => $syllabus?->chapters_count ?? 0,
                'students_count' => $studentsCount,
                'has_syllabus' => (bool) $syllabus,
            ];
        })->values()->all();
    }

    /**
     * @return array{
     *     gradeLevel: array<string, mixed>,
     *     examPlanRows: list<array<string, mixed>>,
     *     examPlanStats: array<string, int>,
     *     examFilter: string,
     *     activeYear: ?array<string, mixed>,
     *     students_count: int,
     * }
     */
    public function classDetail(User $mentor, GradeLevel $gradeLevel, string $examFilter = 'upcoming'): array
    {
        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        if (! in_array($examFilter, ['upcoming', 'past', 'all'], true)) {
            $examFilter = 'upcoming';
        }

        $activeYear = AcademicYear::active();
        $studentIds = $this->mentorService->studentIdsForUser($mentor);

        $examPlanRows = [];
        $examPlanStats = ['with_upcoming' => 0, 'without_plan' => 0, 'without_upcoming' => 0];

        if ($activeYear && $studentIds !== []) {
            $enrollments = StudentEnrollment::query()
                ->with(['student:id,name', 'academicYear', 'gradeLevel:id,name', 'board:id,name'])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->whereIn('student_id', $studentIds)
                ->orderBy('id')
                ->get();

            try {
                $examPlanRows = $this->examPlanService->classHubRows($enrollments, $examFilter, false);
            } catch (Throwable $e) {
                Log::error('Mentor class hub failed to build student rows.', [
                    'grade_level_id' => $gradeLevel->id,
                    'message' => $e->getMessage(),
                ]);
                $examPlanRows = $enrollments->map(fn (StudentEnrollment $enrollment) => [
                    'student_id' => $enrollment->student_id,
                    'student_name' => $enrollment->student?->name,
                    'enrollment_id' => $enrollment->id,
                    'has_plan' => false,
                    'has_upcoming' => false,
                    'upcoming_count' => 0,
                    'display_plan' => null,
                    'all_plans' => [],
                ])->values()->all();
            }

            try {
                $examPlanRows = $this->progress->attachFast($enrollments, $examPlanRows);
            } catch (Throwable $e) {
                Log::error('Mentor class hub failed to attach student progress.', [
                    'grade_level_id' => $gradeLevel->id,
                    'message' => $e->getMessage(),
                ]);
                $examPlanRows = $this->progress->withEmptyProgress($examPlanRows);
            }
            $examPlanStats = [
                'with_upcoming' => collect($examPlanRows)->where('has_upcoming', true)->count(),
                'without_plan' => collect($examPlanRows)->where('has_plan', false)->count(),
                'without_upcoming' => collect($examPlanRows)->where('has_upcoming', false)->count(),
            ];
        }

        return [
            'gradeLevel' => $gradeLevel->only(['id', 'name', 'sort_order']),
            'examPlanRows' => $examPlanRows,
            'examPlanStats' => $examPlanStats,
            'examFilter' => $examFilter,
            'activeYear' => $activeYear?->only(['id', 'name']),
            'students_count' => count($examPlanRows),
            'enrollment_ids' => collect($examPlanRows)->pluck('enrollment_id')->filter()->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return array<int, array<string, mixed>>
     */
    public function studyPlanMetricsForEnrollmentIds(array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $enrollments = StudentEnrollment::query()
            ->with(['student:id,name', 'academicYear'])
            ->whereIn('id', $enrollmentIds)
            ->get();

        return $this->progress->studyPlanMetricsByEnrollment($enrollments);
    }

    /**
     * @return list<int>
     */
    public function enrollmentIdsForGrade(User $mentor, GradeLevel $gradeLevel): array
    {
        $activeYear = AcademicYear::active();
        $studentIds = $this->mentorService->studentIdsForUser($mentor);

        if (! $activeYear || $studentIds === []) {
            return [];
        }

        return StudentEnrollment::query()
            ->where('academic_year_id', $activeYear->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->whereIn('student_id', $studentIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
