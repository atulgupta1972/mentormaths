<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentQuestionCorrection extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const SOURCE_HELP_REQUEST = 'help_request';

    public const SOURCE_ADMIN_RETURN = 'admin_return';

    public const SOURCE_STUDENT_REPORT = 'student_report';

    protected $fillable = [
        'content_upload_task_id',
        'question_id',
        'question_number',
        'question_text',
        'remark',
        'source',
        'status',
        'requested_by',
        'notified_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContentUploadTask::class, 'content_upload_task_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
