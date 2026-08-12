<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BasicsDrillSession extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    public const PHASE_TABLE_SHOW = 'table_show';

    public const PHASE_TABLE_DRILL = 'table_drill';

    public const PHASE_TABLE_RETRY = 'table_retry';

    public const PHASE_SQUARES_SHOW = 'squares_show';

    public const PHASE_SQUARES_DRILL = 'squares_drill';

    public const PHASE_SQUARES_RETRY = 'squares_retry';

    public const PHASE_CUBES_SHOW = 'cubes_show';

    public const PHASE_CUBES_DRILL = 'cubes_drill';

    public const PHASE_CUBES_RETRY = 'cubes_retry';

    public const PHASE_FINAL_CORRECTION = 'final_correction';

    public const PHASE_COMPLETED = 'completed';

    protected $fillable = [
        'student_id',
        'student_enrollment_id',
        'drill_date',
        'status',
        'phase',
        'table_number',
        'square_batch_start',
        'cube_batch_start',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'drill_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function isComplete(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_SKIPPED], true);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BasicsDrillItem::class)->orderBy('sort_order');
    }
}
