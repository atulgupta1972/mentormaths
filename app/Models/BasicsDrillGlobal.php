<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BasicsDrillGlobal extends Model
{
    protected $fillable = [
        'excluded_tables',
    ];

    protected function casts(): array
    {
        return [
            'excluded_tables' => 'array',
        ];
    }
}
