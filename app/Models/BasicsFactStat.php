<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasicsFactStat extends Model
{
    protected $fillable = [
        'student_id',
        'fact_type',
        'fact_key',
        'times_shown',
        'times_correct',
        'times_failed',
        'needs_review',
        'last_shown_date',
    ];

    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'last_shown_date' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
