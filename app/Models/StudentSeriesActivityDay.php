<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSeriesActivityDay extends Model
{
    public const KIND_ALGEBRA = 'algebra';

    public const KIND_MENSURATION = 'mensuration';

    protected $fillable = [
        'student_id',
        'activity_date',
        'kind',
        'pack_key',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'activity_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
