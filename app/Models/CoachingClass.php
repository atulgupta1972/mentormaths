<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoachingClass extends Model
{
    protected $fillable = [
        'name',
        'address',
        'city',
        'phone',
        'pin_code',
        'state',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(CoachingClassTeacher::class)->orderBy('sort_order')->orderBy('name');
    }

    public function activeTeachers(): HasMany
    {
        return $this->teachers()->where('is_active', true);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }
}
