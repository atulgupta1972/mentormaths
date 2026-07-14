<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionSetAudit extends Model
{
    public const STATUS_CLEAN = 'clean';

    public const STATUS_ISSUES = 'issues';

    protected $fillable = [
        'worksheet_id',
        'audited_by',
        'status',
        'issue_count',
        'findings',
    ];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'issue_count' => 'integer',
        ];
    }

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'audited_by');
    }
}
