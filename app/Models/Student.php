<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'date_of_birth',
        'student_mobile',
        'parent1_name',
        'parent1_mobile',
        'parent2_name',
        'parent2_mobile',
        'school_name',
        'email',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function registrationRequests(): HasMany
    {
        return $this->hasMany(RegistrationRequest::class);
    }

    public function currentEnrollment(): ?StudentEnrollment
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return null;
        }

        return $this->enrollmentForYear($activeYear->id);
    }

    public function enrollmentForYear(int $academicYearId): ?StudentEnrollment
    {
        return $this->enrollments()
            ->where('academic_year_id', $academicYearId)
            ->first();
    }

    public function enrollmentHistory()
    {
        return $this->enrollments()
            ->with(['academicYear', 'board', 'gradeLevel'])
            ->join('academic_years', 'academic_years.id', '=', 'student_enrollments.academic_year_id')
            ->orderByDesc('academic_years.starts_on')
            ->select('student_enrollments.*')
            ->get();
    }
}
