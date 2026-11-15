<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppInboxMessage extends Model
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Matches migration `whatsapp_inbox_messages` (Laravel would otherwise guess `whats_app_inbox_messages`). */
    protected $table = 'whatsapp_inbox_messages';

    protected $fillable = [
        'direction',
        'mobile',
        'contact_name',
        'recipient_label',
        'student_id',
        'body',
        'meta_message_id',
        'received_at',
        'read_at',
        'status',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
