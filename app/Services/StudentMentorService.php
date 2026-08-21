<?php

namespace App\Services;

use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Models\StudentMentorAssignment;
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

        if ($source === EnrollmentSource::COACHING && $student->coaching_class_teacher_id) {
            $teacher = $student->relationLoaded('coachingClassTeacher')
                ? $student->coachingClassTeacher
                : $student->coachingClassTeacher()->first();

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

            if (filled($student->parent1_mobile)) {
                return [
                    'type' => EnrollmentSource::MENTOR_PARENT1,
                    'name' => $student->parent1_name ?: 'Parent 1',
                    'mobile' => $student->parent1_mobile,
                    'mentoring_user_id' => null,
                    'label' => 'Parent 1 (fallback)',
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

    private function pickParentMentorType(Student $student): ?string
    {
        if ($student->notify_parent1_mobile && filled($student->parent1_mobile)) {
            return EnrollmentSource::MENTOR_PARENT1;
        }

        if ($student->notify_parent2_mobile && filled($student->parent2_mobile)) {
            return EnrollmentSource::MENTOR_PARENT2;
        }

        if (filled($student->parent1_mobile)) {
            return EnrollmentSource::MENTOR_PARENT1;
        }

        if (filled($student->parent2_mobile)) {
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
}
