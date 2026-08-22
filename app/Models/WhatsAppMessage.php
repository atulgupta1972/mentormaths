<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'channel',
        'to_mobile',
        'recipient_label',
        'student_id',
        'message_body',
        'template_name',
        'meta_message_id',
        'status',
        'error',
        'driver',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            'progress_summary' => 'Weekly summary',
            'daily_balance' => 'Daily balance',
            'assignment_assigned' => 'Assignment',
            'pending_work' => 'Pending work',
            'study_plan_status' => 'Study plan status',
            default => str_replace('_', ' ', $this->channel),
        };
    }
}
