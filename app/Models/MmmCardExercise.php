<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MmmCardExercise extends Model
{
    protected $fillable = [
        'mmm_card_id',
        'sort_order',
        'difficulty',
        'is_demo',
        'question_text',
        'answers',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'is_demo' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(MmmCard::class, 'mmm_card_id');
    }
}
