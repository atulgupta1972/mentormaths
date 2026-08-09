<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentUploaderPayment extends Model
{
    public const METHOD_UPI = 'upi';

    public const METHOD_BANK = 'bank';

    public const METHOD_OTHER = 'other';

    protected $fillable = [
        'content_upload_task_id',
        'batch_id',
        'amount_inr',
        'paid_on',
        'method',
        'upi_or_reference',
        'notes',
        'paid_by_user_id',
        'emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'emailed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContentUploadTask::class, 'content_upload_task_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            self::METHOD_UPI => 'UPI',
            self::METHOD_BANK => 'Bank transfer',
            self::METHOD_OTHER => 'Other',
            default => $this->method,
        };
    }
}
