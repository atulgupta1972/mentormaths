<?php

namespace App\Services;

use App\Models\AccessCode;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\EnrollmentSource;
use App\Support\StudentIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SelfServeAccessService
{
    public function __construct(
        private AccessCodeService $accessCodes,
        private UserGroupService $userGroups,
        private StudentMentorService $mentorService,
    ) {}

    /**
     * @param  array{
     *     class_name: string,
     *     teacher_name: string,
     *     mobile: string,
     *     email: string,
     * }  $data
     * @return array{user: User, coaching_class: CoachingClass, teacher: CoachingClassTeacher, access_code: AccessCode, plain_code: string}
     */
    public function registerMentor(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::query()->create([
                'name' => $data['teacher_name'],
                'email' => $data['email'],
                'password' => 'pending-tcode',
                'role' => User::ROLE_TEACHER,
                'mobile' => $data['mobile'],
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $this->userGroups->attachGroupByCode($user, User::ROLE_TEACHER);
            $this->userGroups->attachGroupByCode($user, User::ROLE_MENTOR);

            $coachingClass = CoachingClass::query()->create([
                'name' => $data['class_name'],
                'phone' => $data['mobile'],
                'is_active' => true,
                'notes' => 'Self-serve mentor signup',
            ]);

            $teacher = CoachingClassTeacher::query()->create([
                'coaching_class_id' => $coachingClass->id,
                'name' => $data['teacher_name'],
                'mobile' => $data['mobile'],
                'email' => $data['email'],
                'user_id' => $user->id,
                'is_active' => true,
                'sort_order' => 1,
            ]);

            $accessCode = $this->accessCodes->issue([
                'type' => AccessCode::TYPE_MENTOR,
                'user' => $user,
                'email' => $data['email'],
                'mobile' => $data['mobile'],
                'coaching_class_id' => $coachingClass->id,
                'coaching_class_teacher_id' => $teacher->id,
                'notes' => 'Self-serve mentor trial',
            ]);

            $user->forceFill(['password' => $accessCode->code])->save();

            return [
                'user' => $user->fresh(),
                'coaching_class' => $coachingClass,
                'teacher' => $teacher,
                'access_code' => $accessCode,
                'plain_code' => $accessCode->code,
            ];
        });
    }

    /**
     * Activate a registration request immediately (no admin approval).
     *
     * @return array{user: User, student: Student, access_code: AccessCode, plain_code: string, login_email: string}
     */
    public function activateStudentRegistration(RegistrationRequest $registrationRequest, ?string $chosenPassword = null): array
    {
        if (StudentIdentity::findPendingRequest(
            $registrationRequest->student_name,
            $registrationRequest->student_mobile,
            $registrationRequest->id,
        )) {
            throw ValidationException::withMessages([
                'student_mobile' => 'Another registration with this name and mobile is already pending.',
            ]);
        }

        $existingStudent = StudentIdentity::findExistingStudent(
            $registrationRequest->student_name,
            $registrationRequest->student_mobile,
        );

        if ($existingStudent && ! StudentIdentity::canReuseStudentProfile($existingStudent)) {
            throw ValidationException::withMessages([
                'student_mobile' => 'A student with this name and mobile is already registered. Log in or contact Mentor Maths.',
            ]);
        }

        $precheck = $this->mentorService->validateMappingPayload([
            'enrollment_source' => $registrationRequest->enrollment_source ?: EnrollmentSource::INDIVIDUAL,
            'coaching_class_id' => $registrationRequest->coaching_class_id,
            'coaching_class_teacher_id' => $registrationRequest->coaching_class_teacher_id,
            'notify_parent1_mobile' => $registrationRequest->notify_parent1_mobile,
            'notify_parent2_mobile' => $registrationRequest->notify_parent2_mobile,
            'parent1_mobile' => $registrationRequest->parent1_mobile,
            'parent2_mobile' => $registrationRequest->parent2_mobile,
        ]);

        if (! $precheck['ok']) {
            throw ValidationException::withMessages([
                'enrollment_source' => $precheck['message'],
            ]);
        }

        return DB::transaction(function () use ($registrationRequest, $existingStudent, $chosenPassword) {
            $source = $registrationRequest->enrollment_source ?: EnrollmentSource::INDIVIDUAL;
            $coachingClassId = $source === EnrollmentSource::COACHING
                ? $registrationRequest->coaching_class_id
                : null;
            $teacherId = $source === EnrollmentSource::COACHING
                ? $registrationRequest->coaching_class_teacher_id
                : null;

            if ($existingStudent) {
                $student = $existingStudent;
                $student->update([
                    'name' => $registrationRequest->student_name,
                    'date_of_birth' => $registrationRequest->date_of_birth,
                    'student_mobile' => $registrationRequest->student_mobile,
                    'parent1_name' => $registrationRequest->parent1_name,
                    'parent1_mobile' => $registrationRequest->parent1_mobile,
                    'parent1_email' => $registrationRequest->parent1_email
                        ?? $student->parent1_email
                        ?? $registrationRequest->email,
                    'parent2_name' => $registrationRequest->parent2_name,
                    'parent2_mobile' => $registrationRequest->parent2_mobile,
                    'school_name' => $registrationRequest->school_name,
                    'email' => $registrationRequest->email,
                    'notify_student_mobile' => (bool) $registrationRequest->notify_student_mobile,
                    'notify_parent1_mobile' => (bool) ($registrationRequest->notify_parent1_mobile ?? true),
                    'notify_parent1_email' => true,
                    'notify_parent2_mobile' => (bool) $registrationRequest->notify_parent2_mobile,
                ]);

                $enrollment = $student->enrollments()
                    ->where('academic_year_id', $registrationRequest->academic_year_id)
                    ->first();

                if ($enrollment) {
                    $enrollment->update([
                        'board_id' => $registrationRequest->board_id,
                        'grade_level_id' => $registrationRequest->grade_level_id,
                        'school_name' => $registrationRequest->school_name,
                        'enrollment_source' => $source,
                        'coaching_class_id' => $coachingClassId,
                        'status' => StudentEnrollment::STATUS_ACTIVE,
                    ]);
                } else {
                    StudentEnrollment::create([
                        'student_id' => $student->id,
                        'academic_year_id' => $registrationRequest->academic_year_id,
                        'board_id' => $registrationRequest->board_id,
                        'grade_level_id' => $registrationRequest->grade_level_id,
                        'school_name' => $registrationRequest->school_name,
                        'enrollment_source' => $source,
                        'coaching_class_id' => $coachingClassId,
                        'status' => StudentEnrollment::STATUS_ACTIVE,
                    ]);
                }

                if ($student->user) {
                    $student->update(['user_id' => null]);
                    $student->user->delete();
                }
            } else {
                $student = Student::create([
                    'name' => $registrationRequest->student_name,
                    'date_of_birth' => $registrationRequest->date_of_birth,
                    'student_mobile' => $registrationRequest->student_mobile,
                    'parent1_name' => $registrationRequest->parent1_name,
                    'parent1_mobile' => $registrationRequest->parent1_mobile,
                    'parent1_email' => $registrationRequest->parent1_email ?? $registrationRequest->email,
                    'parent2_name' => $registrationRequest->parent2_name,
                    'parent2_mobile' => $registrationRequest->parent2_mobile,
                    'school_name' => $registrationRequest->school_name,
                    'email' => $registrationRequest->email,
                    'notify_student_mobile' => (bool) $registrationRequest->notify_student_mobile,
                    'notify_parent1_mobile' => (bool) ($registrationRequest->notify_parent1_mobile ?? true),
                    'notify_parent1_email' => true,
                    'notify_parent2_mobile' => (bool) $registrationRequest->notify_parent2_mobile,
                ]);

                StudentEnrollment::create([
                    'student_id' => $student->id,
                    'academic_year_id' => $registrationRequest->academic_year_id,
                    'board_id' => $registrationRequest->board_id,
                    'grade_level_id' => $registrationRequest->grade_level_id,
                    'school_name' => $registrationRequest->school_name,
                    'enrollment_source' => $source,
                    'coaching_class_id' => $coachingClassId,
                    'status' => StudentEnrollment::STATUS_ACTIVE,
                ]);
            }

            $this->mentorService->map($student, [
                'enrollment_source' => $source,
                'coaching_class_id' => $coachingClassId,
                'coaching_class_teacher_id' => $teacherId,
            ]);

            if (! $this->mentorService->isMapped($student->fresh(['coachingClassTeacher']))) {
                throw ValidationException::withMessages([
                    'enrollment_source' => 'Mentor not linked. For home learning tick Notify on mentor mobile; for coaching select class + teacher.',
                ]);
            }

            $loginEmail = $registrationRequest->email
                ?? 'student.'.$student->id.'@mathsfoundation.local';

            StudentIdentity::releaseInactiveLoginForEmail($loginEmail);

            $user = User::create([
                'name' => $registrationRequest->student_name,
                'email' => $loginEmail,
                'password' => 'pending-tcode',
                'role' => User::ROLE_STUDENT,
                'mobile' => $registrationRequest->student_mobile ?? $registrationRequest->parent1_mobile,
                'email_verified_at' => $registrationRequest->email ? now() : null,
                'is_active' => true,
            ]);

            $student->update(['user_id' => $user->id]);
            $this->userGroups->attachGroupByCode($user, User::ROLE_STUDENT);

            $accessCode = $this->accessCodes->issue([
                'type' => AccessCode::TYPE_STUDENT,
                'user' => $user,
                'email' => $loginEmail,
                'mobile' => $registrationRequest->student_mobile ?? $registrationRequest->parent1_mobile,
                'student_id' => $student->id,
                'coaching_class_id' => $coachingClassId,
                'coaching_class_teacher_id' => $teacherId,
                'notes' => 'Self-serve student trial',
            ]);

            $password = filled($chosenPassword) ? $chosenPassword : $accessCode->code;
            $user->forceFill(['password' => $password])->save();

            $registrationRequest->update([
                'status' => RegistrationRequest::STATUS_APPROVED,
                'student_id' => $student->id,
                'admin_notes' => 'Self-serve activation (tcode). Admin notified only.',
                'reviewed_at' => now(),
            ]);

            return [
                'user' => $user->fresh(),
                'student' => $student->fresh(),
                'access_code' => $accessCode,
                'plain_code' => $accessCode->code,
                'login_email' => $loginEmail,
            ];
        });
    }
}
