<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MensurationMatchItemClass extends Model
{
    protected $table = 'mensuration_match_item_classes';

    protected $fillable = [
        'item_key',
        'classes',
    ];

    protected function casts(): array
    {
        return [
            'classes' => 'array',
        ];
    }
}
