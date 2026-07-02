<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StudentPromotionService
{
    public function promote(
        Student $student,
        AcademicYear $toYear,
        ?GradeLevel $gradeLevel = null,
        ?Board $board = null,
        ?string $schoolName = null,
    ): StudentEnrollment {
        if ($student->enrollments()->where('academic_year_id', $toYear->id)->exists()) {
            throw new InvalidArgumentException("Student is already enrolled for {$toYear->name}.");
        }

        $latestEnrollment = $this->latestEnrollment($student);

        if (! $latestEnrollment) {
            throw new InvalidArgumentException('Student has no previous enrollment to promote from.');
        }

        $gradeLevel ??= $latestEnrollment->gradeLevel->next();
        $board ??= $latestEnrollment->board;
        $schoolName ??= $latestEnrollment->school_name;

        if (! $gradeLevel) {
            throw new InvalidArgumentException('No next class available for promotion.');
        }

        return DB::transaction(function () use ($student, $toYear, $gradeLevel, $board, $schoolName, $latestEnrollment) {
            if ($latestEnrollment->status === StudentEnrollment::STATUS_ACTIVE) {
                $latestEnrollment->update(['status' => StudentEnrollment::STATUS_COMPLETED]);
            }

            return StudentEnrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $toYear->id,
                'board_id' => $board->id,
                'grade_level_id' => $gradeLevel->id,
                'school_name' => $schoolName,
                'status' => StudentEnrollment::STATUS_ACTIVE,
            ]);
        });
    }

    /**
     * @return array{promoted: int, skipped: int, errors: list<string>}
     */
    public function bulkPromote(AcademicYear $fromYear, AcademicYear $toYear): array
    {
        if ($fromYear->id === $toYear->id) {
            throw new InvalidArgumentException('From year and to year must be different.');
        }

        $result = ['promoted' => 0, 'skipped' => 0, 'errors' => []];

        $enrollments = StudentEnrollment::query()
            ->with(['student', 'gradeLevel', 'board'])
            ->where('academic_year_id', $fromYear->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->get();

        foreach ($enrollments as $enrollment) {
            try {
                $this->promote($enrollment->student, $toYear);
                $result['promoted']++;
            } catch (InvalidArgumentException $e) {
                $result['skipped']++;
                $result['errors'][] = "{$enrollment->student->name}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    public function latestEnrollment(Student $student): ?StudentEnrollment
    {
        return $student->enrollments()
            ->with(['academicYear', 'gradeLevel', 'board'])
            ->join('academic_years', 'academic_years.id', '=', 'student_enrollments.academic_year_id')
            ->orderByDesc('academic_years.starts_on')
            ->select('student_enrollments.*')
            ->first();
    }

    public function nextGradeLevel(GradeLevel $current): ?GradeLevel
    {
        return $current->next();
    }
}
