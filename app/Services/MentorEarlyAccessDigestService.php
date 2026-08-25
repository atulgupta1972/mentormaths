<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AccessCode;
use App\Models\Student;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\AssignmentMailer;
use Illuminate\Support\Collection;

class MentorEarlyAccessDigestService
{
    public function __construct(
        private StudentMentorService $mentorService,
    ) {}

    /**
     * Mentors who should receive the early-access daily digest.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        $query = User::query()
            ->where('is_active', true)
            ->whereHas('groups', fn ($q) => $q->where('code', User::ROLE_MENTOR))
            ->orderBy('id');

        if (config('mentor_digest.active_tcode_only', true)) {
            $userIds = AccessCode::query()
                ->where('type', AccessCode::TYPE_MENTOR)
                ->where('status', AccessCode::STATUS_ACTIVE)
                ->whereNotNull('user_id')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->pluck('user_id')
                ->unique()
                ->all();

            if ($userIds === []) {
                return collect();
            }

            $query->whereIn('id', $userIds);
        }

        return $query->get()->filter(function (User $user) {
            return AssignmentMailer::isDeliverableEmail($user->email);
        })->values();
    }

    /**
     * @return array{
     *     mentor_name: string,
     *     as_of_label: string,
     *     login_url: string,
     *     classes_url: string,
     *     register_url: string,
     *     coverage_url: string,
     *     has_students: bool,
     *     students: list<array<string, mixed>>,
     *     stats: array{total: int, with_plan: int, without_plan: int}
     * }
     */
    public function buildPayload(User $mentor): array
    {
        $activeYear = AcademicYear::active();
        $studentIds = $this->mentorService->studentIdsForUser($mentor);
        $students = [];

        if ($activeYear && $studentIds !== []) {
            $enrollments = StudentEnrollment::query()
                ->with([
                    'student:id,name,enrollment_source,coaching_class_id',
                    'student.coachingClass:id,name',
                    'gradeLevel:id,name',
                    'board:id,name',
                ])
                ->where('academic_year_id', $activeYear->id)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->whereIn('student_id', $studentIds)
                ->get()
                ->sortBy(fn (StudentEnrollment $e) => mb_strtolower((string) $e->student?->name))
                ->values();

            $coverageIds = StudentChapterCoverage::query()
                ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
                ->distinct()
                ->pluck('student_enrollment_id')
                ->flip();

            $students = $enrollments->map(function (StudentEnrollment $enrollment) use ($coverageIds) {
                /** @var Student|null $student */
                $student = $enrollment->student;

                return [
                    'id' => $enrollment->student_id,
                    'name' => $student?->name ?? 'Student',
                    'grade_name' => $enrollment->gradeLevel?->name,
                    'board_name' => $enrollment->board?->name,
                    'coaching_class_name' => $student?->coachingClass?->name,
                    'has_study_plan' => $coverageIds->has($enrollment->id),
                ];
            })->all();
        }

        $withPlan = collect($students)->where('has_study_plan', true)->count();
        $total = count($students);

        return [
            'mentor_name' => $mentor->name,
            'as_of_label' => now()->timezone(config('app.timezone'))->format('d M Y'),
            'login_url' => route('login'),
            'classes_url' => route('mentor.classes.index'),
            'register_url' => route('registration.create'),
            'coverage_url' => route('admin.questions.coverage'),
            'has_students' => $total > 0,
            'students' => $students,
            'stats' => [
                'total' => $total,
                'with_plan' => $withPlan,
                'without_plan' => $total - $withPlan,
            ],
        ];
    }
}
