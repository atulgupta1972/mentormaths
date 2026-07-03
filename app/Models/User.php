<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'mobile', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_STUDENT = 'student';

    public const ROLE_PARENT = 'parent';

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class)->withTimestamps();
    }

    public function inGroup(string $code): bool
    {
        if ($this->relationLoaded('groups')) {
            return $this->groups->contains('code', $code);
        }

        return $this->groups()->where('code', $code)->exists();
    }

    public function isAdmin(): bool
    {
        return $this->inGroup(self::ROLE_ADMIN) || $this->role === self::ROLE_ADMIN;
    }

    public function isStudent(): bool
    {
        return $this->inGroup(self::ROLE_STUDENT) || $this->role === self::ROLE_STUDENT;
    }

    public function isTeacher(): bool
    {
        return $this->inGroup(self::ROLE_TEACHER) || $this->role === self::ROLE_TEACHER;
    }

    public function isParent(): bool
    {
        return $this->inGroup(self::ROLE_PARENT) || $this->role === self::ROLE_PARENT;
    }

    public function isActiveAccount(): bool
    {
        return $this->is_active !== false;
    }
}
