<?php

namespace App\Services;

use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Models\StudentMentorAssignment;
use App\Models\User;
use App\Support\EnrollmentSource;
use App\Support\StudentIdentity;
use Illuminate\Support\Collection;
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

        if ($source === EnrollmentSource::COACHING && $student->coaching_class_teacher_id) {
            $teacher = $student->relationLoaded('coachingClassTeacher')
                ? $student->coachingClassTeacher
                : $student->coachingClassTeacher()->first();

            if ($teacher) {
                $mentorUserId = $teacher->user_id
                    ? (int) $teacher->user_id
                    : $this->findMentorUserIdByMobile($teacher->mobile);

                return [
                    'type' => EnrollmentSource::MENTOR_COACHING_TEACHER,
                    'name' => $teacher->name,
                    'mobile' => $teacher->mobile,
                    'mentoring_user_id' => $mentorUserId,
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
                    'mentoring_user_id' => $this->findMentorUserIdByMobile($student->parent1_mobile),
                    'label' => 'Parent (communication)',
                ];
            }

            if ($student->notify_parent2_mobile && filled($student->parent2_mobile)) {
                return [
                    'type' => EnrollmentSource::MENTOR_PARENT2,
                    'name' => $student->parent2_name ?: 'Parent 2',
                    'mobile' => $student->parent2_mobile,
                    'mentoring_user_id' => $this->findMentorUserIdByMobile($student->parent2_mobile),
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
                    $this->linkTeacherToMentorUser($teacher);
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
            if (! $mentorUserId) {
                $resolved = $this->resolve($student);
                if (! empty($resolved['mentoring_user_id'])) {
                    $student->mentor_user_id = $resolved['mentoring_user_id'];
                }
            }
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
     * Summary row for admin student lists.
     *
     * @return array{mapped: bool, label: string, name: ?string, mobile: ?string, source: string, source_label: string, login_linked: bool}
     */
    public function summaryForList(Student $student): array
    {
        $resolved = $this->resolve($student);
        $source = $student->enrollment_source ?: EnrollmentSource::INDIVIDUAL;
        $mapped = $this->isMapped($student);
        $loginLinked = (bool) ($resolved['mentoring_user_id'] ?? null);

        return [
            'mapped' => $mapped,
            'label' => $mapped ? $resolved['label'] : 'Not mapped',
            'name' => $mapped ? $resolved['name'] : null,
            'mobile' => $mapped ? $resolved['mobile'] : null,
            'source' => $source,
            'source_label' => EnrollmentSource::label($source),
            'login_linked' => $loginLinked,
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

        if ($mentorUserId && ! $student->mentor_user_id) {
            $student->forceFill(['mentor_user_id' => $mentorUserId])->save();
        }

        if (
            $mentorUserId
            && $student->coachingClassTeacher
            && ! $student->coachingClassTeacher->user_id
        ) {
            $this->linkTeacherToMentorUser($student->coachingClassTeacher);
        }

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
        // Heal coaching-teacher → mentor-user links by matching mobile (admin often
        // creates teachers without user_id, so Mapped shows in admin but mentor login is empty).
        $this->linkTeachersMatchingUserMobile($user);

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

        $fromParentMobile = $this->studentIdsMatchingNotifyParentMobile($user);

        return $fromTeachers
            ->merge($fromMentorUser)
            ->merge($fromAssignments)
            ->merge($fromParentMobile)
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

    /**
     * Link a coaching teacher row to a mentor User when mobiles match.
     */
    public function linkTeacherToMentorUser(CoachingClassTeacher $teacher): ?User
    {
        if ($teacher->user_id) {
            return User::query()->find($teacher->user_id);
        }

        $mentor = $this->findMentorUserByMobile($teacher->mobile);

        if (! $mentor) {
            return null;
        }

        $teacher->forceFill(['user_id' => $mentor->id])->save();

        return $mentor;
    }

    public function findMentorUserIdByMobile(?string $mobile): ?int
    {
        return $this->findMentorUserByMobile($mobile)?->id;
    }

    public function findMentorUserByMobile(?string $mobile): ?User
    {
        $needle = StudentIdentity::normalizeMobile($mobile);

        if (! $needle) {
            return null;
        }

        return User::query()
            ->where(function ($query) {
                $query->where('role', User::ROLE_MENTOR)
                    ->orWhereHas('groups', fn ($groups) => $groups->where('code', User::ROLE_MENTOR));
            })
            ->whereNotNull('mobile')
            ->where('mobile', '!=', '')
            ->get(['id', 'name', 'mobile', 'role'])
            ->first(fn (User $candidate) => StudentIdentity::normalizeMobile($candidate->mobile) === $needle);
    }

    private function linkTeachersMatchingUserMobile(User $user): void
    {
        $needle = StudentIdentity::normalizeMobile($user->mobile);

        if (! $needle) {
            return;
        }

        CoachingClassTeacher::query()
            ->whereNull('user_id')
            ->where(function ($query) use ($needle) {
                $query->where('mobile', $needle)
                    ->orWhere('mobile', 'like', '%'.$needle);
            })
            ->get()
            ->each(function (CoachingClassTeacher $teacher) use ($needle, $user) {
                if (StudentIdentity::normalizeMobile($teacher->mobile) === $needle) {
                    $teacher->forceFill(['user_id' => $user->id])->save();
                }
            });
    }

    /**
     * Individual enrollments where notify-parent mobile matches this mentor's mobile.
     *
     * @return Collection<int, int>
     */
    private function studentIdsMatchingNotifyParentMobile(User $user): Collection
    {
        $needle = StudentIdentity::normalizeMobile($user->mobile);

        if (! $needle) {
            return collect();
        }

        $candidates = Student::query()
            ->where(function ($query) use ($needle) {
                $query->where(function ($q) use ($needle) {
                    $q->where('notify_parent1_mobile', true)
                        ->where(function ($m) use ($needle) {
                            $m->where('parent1_mobile', $needle)
                                ->orWhere('parent1_mobile', 'like', '%'.$needle);
                        });
                })->orWhere(function ($q) use ($needle) {
                    $q->where('notify_parent2_mobile', true)
                        ->where(function ($m) use ($needle) {
                            $m->where('parent2_mobile', $needle)
                                ->orWhere('parent2_mobile', 'like', '%'.$needle);
                        });
                });
            })
            ->get(['id', 'notify_parent1_mobile', 'parent1_mobile', 'notify_parent2_mobile', 'parent2_mobile', 'mentor_user_id']);

        $ids = collect();

        foreach ($candidates as $student) {
            $match = (
                $student->notify_parent1_mobile
                && StudentIdentity::normalizeMobile($student->parent1_mobile) === $needle
            ) || (
                $student->notify_parent2_mobile
                && StudentIdentity::normalizeMobile($student->parent2_mobile) === $needle
            );

            if (! $match) {
                continue;
            }

            $ids->push((int) $student->id);

            if (! $student->mentor_user_id) {
                $student->forceFill(['mentor_user_id' => $user->id])->save();
                $this->syncMentorAssignment($student->fresh(['coachingClassTeacher', 'mentorUser']));
            }
        }

        return $ids;
    }
}
