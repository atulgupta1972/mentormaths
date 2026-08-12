<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormulaDrillItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CORRECT = 'correct';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXHAUSTED = 'exhausted';

    public const ROUND_MAIN = 'main';

    public const ROUND_CORRECTION = 'correction';

    protected $fillable = [
        'formula_drill_session_id',
        'question_id',
        'practice_correction_item_id',
        'sort_order',
        'round',
        'status',
        'attempt_count',
        'failure_count',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(FormulaDrillSession::class, 'formula_drill_session_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function needsEndCorrection(): bool
    {
        return $this->failure_count > 0 || $this->status === self::STATUS_EXHAUSTED;
    }

    public function isDone(): bool
    {
        return in_array($this->status, [
            self::STATUS_CORRECT,
            self::STATUS_FAILED,
            self::STATUS_EXHAUSTED,
        ], true);
    }

    public function isMainRound(): bool
    {
        return ($this->round ?? self::ROUND_MAIN) === self::ROUND_MAIN;
    }
}
