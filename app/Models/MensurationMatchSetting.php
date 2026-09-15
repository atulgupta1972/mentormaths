<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensurationMatchSetting extends Model
{
    protected $fillable = [
        'grade_level_id',
        'enabled',
        'perimeter_area_enabled',
        'volume_enabled',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'perimeter_area_enabled' => 'boolean',
            'volume_enabled' => 'boolean',
        ];
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }
}
