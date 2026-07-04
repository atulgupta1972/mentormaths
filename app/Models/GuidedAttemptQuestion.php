<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuidedAttemptQuestion extends Model
{
    public const PHASE_PENDING = 'pending';

    public const PHASE_ANSWERING = 'answering';

    public const PHASE_RETRY = 'retry';

    public const PHASE_EXPLAINED = 'explained';

    public const PHASE_DONE = 'done';

    public const PHASE_GIVEN_UP = 'given_up';

    protected $fillable = [
        'set_attempt_id',
        'question_id',
        'sort_order',
        'phase',
        'wrong_before_explanation',
        'first_try_correct',
        'corrected_after_help',
        'gave_up',
        'final_option_id',
        'final_is_correct',
    ];

    protected function casts(): array
    {
        return [
            'first_try_correct' => 'boolean',
            'corrected_after_help' => 'boolean',
            'gave_up' => 'boolean',
            'final_is_correct' => 'boolean',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(SetAttempt::class, 'set_attempt_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function isFinished(): bool
    {
        return in_array($this->phase, [self::PHASE_DONE, self::PHASE_GIVEN_UP], true);
    }
}
