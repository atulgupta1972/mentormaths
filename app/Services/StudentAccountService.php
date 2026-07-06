<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StudentAccountService
{
    public function isActive(Student $student): bool
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return false;
        }

        $enrollment = $student->enrollmentForYear($activeYear->id);

        if (! $enrollment || $enrollment->status !== StudentEnrollment::STATUS_ACTIVE) {
            return false;
        }

        $student->loadMissing('user');

        if ($student->user && ! $student->user->isActiveAccount()) {
            return false;
        }

        return true;
    }

    public function deactivate(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $activeYear = AcademicYear::active();

            if ($activeYear) {
                $enrollment = $student->enrollmentForYear($activeYear->id);

                if ($enrollment && $enrollment->status === StudentEnrollment::STATUS_ACTIVE) {
                    $enrollment->update(['status' => StudentEnrollment::STATUS_INACTIVE]);
                }
            }

            $student->loadMissing('user');

            if ($student->user) {
                $student->user->update(['is_active' => false]);
            }
        });
    }

    public function activate(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $activeYear = AcademicYear::active();

            if (! $activeYear) {
                throw new InvalidArgumentException('No active academic year is set.');
            }

            $enrollment = $student->enrollmentForYear($activeYear->id);

            if (! $enrollment) {
                throw new InvalidArgumentException('Student has no enrollment for the current academic year.');
            }

            if ($enrollment->status === StudentEnrollment::STATUS_INACTIVE) {
                $enrollment->update(['status' => StudentEnrollment::STATUS_ACTIVE]);
            } elseif ($enrollment->status !== StudentEnrollment::STATUS_ACTIVE) {
                throw new InvalidArgumentException('Only inactive enrollments can be reactivated from here.');
            }

            $student->loadMissing('user');

            if ($student->user) {
                $student->user->update(['is_active' => true]);
            }
        });
    }

    public function delete(Student $student): void
    {
        DB::transaction(function () use ($student) {
            $student->loadMissing('user');
            $user = $student->user;

            $student->delete();

            if ($user) {
                $user->delete();
            }
        });
    }
}
