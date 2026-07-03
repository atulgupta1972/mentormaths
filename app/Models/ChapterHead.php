<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChapterHead extends Model
{
    protected $fillable = [
        'name',
        'sort_order',
    ];

    public function chapters(): HasMany
    {
        return $this->hasMany(SyllabusChapter::class);
    }
}
