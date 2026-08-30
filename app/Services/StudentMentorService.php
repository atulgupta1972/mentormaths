<?php

namespace App\Services;

use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Models\StudentMentorAssignment;
use App\Models\User;
use App\Support\EnrollmentSource;
use Illuminate\Support\Facades\Auth;

class StudentMentorService
{
    /**
     * Resolve display mentor from enrollment source + contacts / coaching teacher.
     *
     * @return array{type: ?string, name: ?string, mobile: ?string, mentoring_user_id: ?int, label: string}
     */
    public function resolve(Student $student): array
    {
        $source = $student->enrollment_source ?: EnrollmentSource::INDIVIDUAL;

        if ($source === EnrollmentSource::COACHING) {
            $teacher = $student->relationLoaded('coachingClassTeacher')
                ? $student->coachingClassTeacher
                : ($student->coaching_class_teacher_id
                    ? $student->coachingClassTeacher()->first()
                    : null);

            if ($teacher) {
                return [
                    'type' => EnrollmentSource::MENTOR_COACHING_TEACHER,
                    'name' => $teacher->name,
                    'mobile' => $teacher->mobile,
                    'mentoring_user_id' => $teacher->user_id,
                    'label' => 'Coaching teacher',
                ];
            }
        }

        if ($source === EnrollmentSource::INDIVIDUAL || $source === EnrollmentSource::SCHOOL) {
            if ($student->notify_parent1_mobile && filled($student->parent1_mobile)) {
                return [
                    'type' => EnrollmentSource::MENTOR_PARENT1,
                    'name' => $student->parent1_name ?: 'Parent 1',
                    'mobile' => $student->parent1_mobile,
                    'mentoring_user_id' => null,
                    'label' => 'Parent (communication)',
                ];
            }

            if ($student->notify_parent2_mobile && filled($student->parent2_mobile)) {
                return [
                    'type' => EnrollmentSource::MENTOR_PARENT2,
                    'name' => $student->parent2_name ?: 'Parent 2',
                    'mobile' => $student->parent2_mobile,
                    'mentoring_user_id' => null,
                    'label' => 'Parent (communication)',
                ];
            }
        }

        if ($student->mentor_user_id) {
            $user = $student->relationLoaded('mentorUser')
                ? $student->mentorUser
                : $student->mentorUser()->first();

            return [
                'type' => EnrollmentSource::MENTOR_USER,
                'name' => $user?->name,
                'mobile' => $user?->mobile,
                'mentoring_user_id' => $student->mentor_user_id,
                'label' => 'Platform mentor',
            ];
        }

        return [
            'type' => null,
            'name' => null,
            'mobile' => null,
            'mentoring_user_id' => null,
            'label' => 'Not mapped',
        ];
    }

    /**
     * Persist enrollment/mentor mapping and sync student_mentor_assignments when a mentor User exists.
     *
     * @param  array{
     *     enrollment_source: string,
     *     coaching_class_id?: int|null,
     *     coaching_class_teacher_id?: int|null,
     *     mentor_user_id?: int|null,
     * }  $data
     */
    public function map(Student $student, array $data): Student
    {
        $source = $data['enrollment_source'] ?? EnrollmentSource::INDIVIDUAL;

        $coachingClassId = null;
        $teacherId = null;
        $mentorUserId = $data['mentor_user_id'] ?? null;
        $mentorType = null;

        if ($source === EnrollmentSource::COACHING) {
            $coachingClassId = $data['coaching_class_id'] ?? null;
            $teacherId = $data['coaching_class_teacher_id'] ?? null;

            if ($teacherId) {
                $teacher = CoachingClassTeacher::query()->find($teacherId);
                if ($teacher && (int) $teacher->coaching_class_id === (int) $coachingClassId) {
                    $mentorType = EnrollmentSource::MENTOR_COACHING_TEACHER;
                    $mentorUserId = $teacher->user_id ?: $mentorUserId;
                } else {
                    $teacherId = null;
                }
            }
        }

        $student->fill([
            'enrollment_source' => $source,
            'coaching_class_id' => $source === EnrollmentSource::COACHING ? $coachingClassId : null,
            'coaching_class_teacher_id' => $source === EnrollmentSource::COACHING ? $teacherId : null,
            'mentor_user_id' => $mentorUserId,
        ]);

        if ($source === EnrollmentSource::COACHING) {
            $student->mentor_type = $mentorType;
        } else {
            $student->mentor_type = $this->pickParentMentorType($student);
        }

        $student->save();

        $this->syncMentorAssignment($student);

        return $student->fresh([
            'coachingClass',
            'coachingClassTeacher',
            'mentorUser',
        ]);
    }

    public function applyDefaultsForIndividual(Student $student): void
    {
        if (! $student->enrollment_source) {
            $student->enrollment_source = EnrollmentSource::INDIVIDUAL;
        }

        if ($student->enrollment_source === EnrollmentSource::INDIVIDUAL && ! $student->mentor_type) {
            $student->mentor_type = $this->pickParentMentorType($student);
            $student->save();
        }
    }

    /** True when enrollment has a real mentor link (notify parent or coaching teacher). */
    public function isMapped(Student $student): bool
    {
        $source = $student->enrollment_source ?: EnrollmentSource::INDIVIDUAL;

        if ($source === EnrollmentSource::COACHING) {
            $teacher = $student->relationLoaded('coachingClassTeacher')
                ? $student->coachingClassTeacher
                : $student->coachingClassTeacher()->first();

            return (bool) ($teacher && filled($teacher->name) && filled($teacher->mobile));
        }

        if ($student->mentor_user_id) {
            return true;
        }

        if ($student->notify_parent1_mobile && filled($student->parent1_mobile)) {
            return true;
        }

        if ($student->notify_parent2_mobile && filled($student->parent2_mobile)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{ok: bool, message: ?string}
     */
    public function validateMappingPayload(array $data, ?Student $student = null): array
    {
        $source = $data['enrollment_source'] ?? EnrollmentSource::INDIVIDUAL;

        if ($source === EnrollmentSource::COACHING) {
            if (empty($data['coaching_class_id']) || empty($data['coaching_class_teacher_id'])) {
                return [
                    'ok' => false,
                    'message' => 'Select coaching class and teacher (mentor) before enrollment.',
                ];
            }

            return ['ok' => true, 'message' => null];
        }

        if ($source === EnrollmentSource::SCHOOL) {
            return [
                'ok' => false,
                'message' => 'School enrollment is not open yet. Use Individual or Coaching.',
            ];
        }

        // Individual — need a communication parent as mentor.
        $notify1 = (bool) ($data['notify_parent1_mobile'] ?? $student?->notify_parent1_mobile);
        $notify2 = (bool) ($data['notify_parent2_mobile'] ?? $student?->notify_parent2_mobile);
        $mobile1 = $data['parent1_mobile'] ?? $student?->parent1_mobile;
        $mobile2 = $data['parent2_mobile'] ?? $student?->parent2_mobile;

        if (($notify1 && filled($mobile1)) || ($notify2 && filled($mobile2))) {
            return ['ok' => true, 'message' => null];
        }

        return [
            'ok' => false,
            'message' => 'Tick Notify on mentor mobile — that contact is the mentor for individual enrollment.',
        ];
    }

    /**
     * Read-only mentor card for the student's own profile and dashboard.
     *
     * Shows name/mobile even when admin mapping is incomplete, as long as a
     * coaching teacher or notified parent contact exists.
     *
     * @return array{mapped: bool, name: ?string, mobile: ?string, label: string, coaching_class: ?string}
     */
    public function forStudentView(Student $student): array
    {
        $this->loadStudentViewRelations($student);

        $resolved = $this->resolve($student);
        $hasDetails = filled($resolved['name']) || filled($resolved['mobile']);
        $mapped = $this->isMapped($student) || $hasDetails;
        $source = $student->enrollment_source ?: EnrollmentSource::INDIVIDUAL;
        $coachingClass = $student->coachingClass?->name
            ?? $student->coachingClassTeacher?->coachingClass?->name;

        return [
            'mapped' => $mapped,
            'name' => $hasDetails ? $resolved['name'] : null,
            'mobile' => $hasDetails ? $resolved['mobile'] : null,
            'label' => $hasDetails ? $resolved['label'] : 'Not assigned yet',
            'coaching_class' => $source === EnrollmentSource::COACHING
                ? ($coachingClass ?: null)
                : null,
        ];
    }

    private function loadStudentViewRelations(Student $student): void
    {
        if (! $student->relationLoaded('coachingClassTeacher')) {
            $student->load('coachingClassTeacher.coachingClass');
        } elseif ($student->coachingClassTeacher
            && ! $student->coachingClassTeacher->relationLoaded('coachingClass')) {
            $student->coachingClassTeacher->load('coachingClass');
        }

        $student->loadMissing(['coachingClass', 'mentorUser']);
    }

    /**
     * Summary row for admin student lists.
     *
     * @return array{mapped: bool, label: string, name: ?string, mobile: ?string, source: string, source_label: string}
     */
    public function summaryForList(Student $student): array
    {
        $resolved = $this->resolve($student);
        $source = $student->enrollment_source ?: EnrollmentSource::INDIVIDUAL;
        $mapped = $this->isMapped($student);

        return [
            'mapped' => $mapped,
            'label' => $mapped ? $resolved['label'] : 'Not mapped',
            'name' => $mapped ? $resolved['name'] : null,
            'mobile' => $mapped ? $resolved['mobile'] : null,
            'source' => $source,
            'source_label' => EnrollmentSource::label($source),
        ];
    }

    private function pickParentMentorType(Student $student): ?string
    {
        if ($student->notify_parent1_mobile && filled($student->parent1_mobile)) {
            return EnrollmentSource::MENTOR_PARENT1;
        }

        if ($student->notify_parent2_mobile && filled($student->parent2_mobile)) {
            return EnrollmentSource::MENTOR_PARENT2;
        }

        return null;
    }

    private function syncMentorAssignment(Student $student): void
    {
        $resolved = $this->resolve($student->loadMissing('coachingClassTeacher', 'mentorUser'));
        $mentorUserId = $resolved['mentoring_user_id'] ?? $student->mentor_user_id;

        $active = StudentMentorAssignment::query()
            ->where('student_id', $student->id)
            ->where('is_active', true)
            ->get();

        if (! $mentorUserId) {
            foreach ($active as $row) {
                $row->update([
                    'is_active' => false,
                    'ended_at' => now(),
                ]);
            }

            return;
        }

        $matching = $active->firstWhere('mentor_user_id', $mentorUserId);

        foreach ($active as $row) {
            if ($matching && $row->id === $matching->id) {
                continue;
            }
            $row->update([
                'is_active' => false,
                'ended_at' => now(),
            ]);
        }

        if (! $matching) {
            StudentMentorAssignment::create([
                'student_id' => $student->id,
                'mentor_user_id' => $mentorUserId,
                'is_active' => true,
                'assigned_by_user_id' => Auth::id(),
                'started_at' => now(),
                'notes' => 'Mapped via enrollment source: '.$student->enrollment_source,
            ]);
        }
    }

    /**
     * Student IDs linked to this platform mentor (coaching teacher user or mentor_user_id).
     *
     * @return list<int>
     */
    public function studentIdsForUser(User $user): array
    {
        $teacherIds = CoachingClassTeacher::query()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->all();

        $fromTeachers = $teacherIds === []
            ? collect()
            : Student::query()
                ->whereIn('coaching_class_teacher_id', $teacherIds)
                ->pluck('id');

        $fromMentorUser = Student::query()
            ->where('mentor_user_id', $user->id)
            ->pluck('id');

        $fromAssignments = StudentMentorAssignment::query()
            ->where('mentor_user_id', $user->id)
            ->where('is_active', true)
            ->pluck('student_id');

        return $fromTeachers
            ->merge($fromMentorUser)
            ->merge($fromAssignments)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function canAccessStudent(User $user, int $studentId): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isMentor()) {
            return false;
        }

        return in_array($studentId, $this->studentIdsForUser($user), true);
    }

    public function assertCanAccessStudent(User $user, int $studentId): void
    {
        if (! $this->canAccessStudent($user, $studentId)) {
            abort(403, 'You can only view students enrolled under you.');
        }
    }
}
