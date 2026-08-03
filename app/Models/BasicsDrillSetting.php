<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BasicsDrillSetting extends Model
{
    protected $fillable = [
        'grade_level_id',
        'tables_enabled',
        'squares_enabled',
        'cubes_enabled',
        'table_from',
        'table_to',
        'multiplier_from',
        'multiplier_to',
        'square_from',
        'square_to',
        'cube_from',
        'cube_to',
        'squares_per_day',
        'cubes_per_day',
        'seconds_per_blank',
    ];

    protected function casts(): array
    {
        return [
            'tables_enabled' => 'boolean',
            'squares_enabled' => 'boolean',
            'cubes_enabled' => 'boolean',
        ];
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }
}
