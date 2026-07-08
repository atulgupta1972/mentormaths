<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionBlankAnswer extends Model
{
    public const FORMAT_INTEGER = 'integer';

    public const FORMAT_DECIMAL = 'decimal';

    public const FORMAT_FRACTION = 'fraction';

    public const FORMAT_TEXT = 'text';

    protected $fillable = [
        'question_id',
        'answer_format',
        'correct_answer',
        'decimal_places',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * @return list<string>
     */
    public static function formats(): array
    {
        return [
            self::FORMAT_INTEGER,
            self::FORMAT_DECIMAL,
            self::FORMAT_FRACTION,
            self::FORMAT_TEXT,
        ];
    }
}
