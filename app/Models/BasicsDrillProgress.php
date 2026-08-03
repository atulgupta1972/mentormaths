<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasicsDrillProgress extends Model
{
    protected $table = 'basics_drill_progress';

    protected $fillable = [
        'student_id',
        'student_enrollment_id',
        'next_table',
        'square_batch_start',
        'cube_batch_start',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
