<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentWorkSession extends Model
{
    protected $fillable = [
        'content_upload_task_id',
        'user_id',
        'started_at',
        'ended_at',
        'active_seconds',
        'idle_paused_seconds',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ContentUploadTask::class, 'content_upload_task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
