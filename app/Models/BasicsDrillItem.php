<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasicsDrillItem extends Model
{
    public const TYPE_TABLE = 'table';

    public const TYPE_SQUARE = 'square';

    public const TYPE_CUBE = 'cube';

    public const ROUND_MAIN = 'main';

    public const ROUND_RETRY = 'retry';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CORRECT = 'correct';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVEALED = 'revealed';

    protected $fillable = [
        'basics_drill_session_id',
        'fact_type',
        'fact_key',
        'operand_a',
        'operand_b',
        'correct_answer',
        'sort_order',
        'round',
        'status',
        'response_ms',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(BasicsDrillSession::class, 'basics_drill_session_id');
    }

    public function promptLabel(): string
    {
        return match ($this->fact_type) {
            self::TYPE_TABLE => "{$this->operand_a} × {$this->operand_b}",
            self::TYPE_SQUARE => "{$this->operand_a}²",
            self::TYPE_CUBE => "{$this->operand_a}³",
            default => $this->fact_key,
        };
    }
}
