<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessCode extends Model
{
    public const TYPE_STUDENT = 'student';

    public const TYPE_MENTOR = 'mentor';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'code',
        'type',
        'status',
        'user_id',
        'student_id',
        'coaching_class_id',
        'coaching_class_teacher_id',
        'email',
        'mobile',
        'generated_at',
        'expires_at',
        'extended_at',
        'extension_days_total',
        'payment_reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
            'extended_at' => 'datetime',
            'extension_days_total' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function coachingClass(): BelongsTo
    {
        return $this->belongsTo(CoachingClass::class);
    }

    public function coachingClassTeacher(): BelongsTo
    {
        return $this->belongsTo(CoachingClassTeacher::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at?->isFuture();
    }

    public function isExpired(): bool
    {
        if ($this->status === self::STATUS_REVOKED) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function markExpiredIfNeeded(): void
    {
        if ($this->status === self::STATUS_ACTIVE && $this->expires_at?->isPast()) {
            $this->forceFill(['status' => self::STATUS_EXPIRED])->save();
        }
    }
}
