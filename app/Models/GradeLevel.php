<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeLevel extends Model
{
    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
        'protect_test_attempts',
        'protect_practice_attempts',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'protect_test_attempts' => 'boolean',
            'protect_practice_attempts' => 'boolean',
        ];
    }

    public function registrationRequests(): HasMany
    {
        return $this->hasMany(RegistrationRequest::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    public function next(): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('sort_order', $this->sort_order + 1)
            ->first();
    }

    /** Typical student age at start of academic year (CBSE: Class N ≈ N + 5 years). */
    public function typicalAge(): int
    {
        return (int) $this->sort_order + 5;
    }

    public function nameWithAge(): string
    {
        return "{$this->name} (age {$this->typicalAge()})";
    }
}
