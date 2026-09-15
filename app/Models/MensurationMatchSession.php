<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensurationMatchSession extends Model
{
    public const STATUS_READY = 'ready';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'student_id',
        'student_enrollment_id',
        'drill_date',
        'board',
        'status',
        'total_items',
        'correct_count',
        'item_keys',
        'answers',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'drill_date' => 'date',
            'item_keys' => 'array',
            'answers' => 'array',
            'started_at' => 'datetime',
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
}
