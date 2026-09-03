<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BasicsDrillItem extends Model
{
    public const TYPE_TABLE = 'table';

    public const TYPE_SQUARE = 'square';

    public const TYPE_CUBE = 'cube';

    public const TYPE_FORMULA = 'formula';

    public const ROUND_MAIN = 'main';

    public const ROUND_RETRY = 'retry';

    public const ROUND_CORRECTION = 'correction';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CORRECT = 'correct';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVEALED = 'revealed';

    protected $fillable = [
        'basics_drill_session_id',
        'question_id',
        'practice_correction_item_id',
        'source_formula_drill_item_id',
        'source_basics_drill_item_id',
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

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function isFormulaMcq(): bool
    {
        if ($this->fact_type !== self::TYPE_FORMULA || $this->question_id === null) {
            return false;
        }

        $this->loadMissing('question');

        return $this->question?->isMcq() ?? false;
    }

    public function isFormulaFillBlank(): bool
    {
        if ($this->fact_type !== self::TYPE_FORMULA || $this->question_id === null) {
            return false;
        }

        $this->loadMissing('question');

        return $this->question?->isFillInBlank() ?? false;
    }

    public function promptLabel(): string
    {
        return match ($this->fact_type) {
            self::TYPE_TABLE => $this->isTableReverse()
                ? ('We get '.($this->operand_a * $this->operand_b).' when we multiply '.$this->operand_a.' by ____')
                : "{$this->operand_a} × {$this->operand_b}",
            self::TYPE_SQUARE => "{$this->operand_a}²",
            self::TYPE_CUBE => "{$this->operand_a}³",
            self::TYPE_FORMULA => 'Formula',
            default => $this->fact_key,
        };
    }

    public function isTableReverse(): bool
    {
        return $this->fact_type === self::TYPE_TABLE
            && str_ends_with((string) $this->fact_key, '_rev');
    }
}
