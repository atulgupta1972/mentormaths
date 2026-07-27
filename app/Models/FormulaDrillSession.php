<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormulaDrillSession extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'student_id',
        'student_enrollment_id',
        'drill_date',
        'status',
        'questions_total',
        'questions_completed',
        'pool_size',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'drill_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FormulaDrillItem::class)->orderBy('sort_order');
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_SKIPPED], true);
    }
}
