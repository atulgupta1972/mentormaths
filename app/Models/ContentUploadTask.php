<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentUploadTask extends Model
{
    public const STATUS_PENDING_AGREEMENT = 'pending_agreement';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_VERIFICATION_IN_PROGRESS = 'verification_in_progress';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_SUBMITTED_FOR_PUBLISH = 'submitted_for_publish';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'textbook_chapter_id',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'status',
        'offered_amount_inr',
        'agreed_amount_inr',
        'agreed_at',
        'duplicate_override_reason',
        'duplicate_override_by',
        'submitted_at',
        'published_at',
        'published_by',
        'admin_notes',
    ];

    protected function casts(): array
    {
        return [
            'agreed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function textbookChapter(): BelongsTo
    {
        return $this->belongsTo(TextbookChapter::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(ContentWorkSession::class);
    }

    public function verificationRuns(): HasMany
    {
        return $this->hasMany(ContentVerificationRun::class);
    }

    public function latestVerificationRun(): HasOne
    {
        return $this->hasOne(ContentVerificationRun::class)->latestOfMany();
    }

    public function payment(): HasOne
    {
        return $this->hasOne(ContentUploaderPayment::class);
    }

    public function payableAmountInr(): int
    {
        return (int) ($this->agreed_amount_inr ?? $this->offered_amount_inr ?? 0);
    }

    public function isPayable(): bool
    {
        return in_array($this->status, [
            self::STATUS_VERIFIED,
            self::STATUS_SUBMITTED_FOR_PUBLISH,
            self::STATUS_PUBLISHED,
        ], true) && $this->payableAmountInr() > 0;
    }

    public function isAwaitingAgreement(): bool
    {
        return $this->status === self::STATUS_PENDING_AGREEMENT;
    }

    public function canAssigneeWork(): bool
    {
        return in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_UPLOADED,
            self::STATUS_VERIFICATION_IN_PROGRESS,
            self::STATUS_VERIFIED,
        ], true);
    }

    /**
     * Uploader dashboard bucket: upload_pending | review_pending | done
     */
    public function uploaderBucket(): string
    {
        if (in_array($this->status, [
            self::STATUS_SUBMITTED_FOR_PUBLISH,
            self::STATUS_PUBLISHED,
            self::STATUS_CANCELLED,
        ], true)) {
            return 'done';
        }

        if ($this->status === self::STATUS_PENDING_AGREEMENT) {
            return 'upload_pending';
        }

        $chapter = $this->textbookChapter;
        $hasPublishedSets = $chapter && $chapter->mcqWorksheetIds() !== [];

        if ($this->status === self::STATUS_IN_PROGRESS && ! $hasPublishedSets) {
            return 'upload_pending';
        }

        if ($hasPublishedSets && in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_UPLOADED,
            self::STATUS_VERIFICATION_IN_PROGRESS,
            self::STATUS_VERIFIED,
        ], true)) {
            return 'review_pending';
        }

        return 'upload_pending';
    }

    public function uploaderBucketLabel(): string
    {
        return match ($this->uploaderBucket()) {
            'upload_pending' => 'Upload pending',
            'review_pending' => 'Review pending',
            default => 'Complete',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_AGREEMENT => 'Awaiting rate agreement',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_UPLOADED => 'Uploaded — verify questions',
            self::STATUS_VERIFICATION_IN_PROGRESS => 'Verification in progress',
            self::STATUS_VERIFIED => 'Verified — ready to publish',
            self::STATUS_SUBMITTED_FOR_PUBLISH => 'Submitted — admin to publish',
            self::STATUS_PUBLISHED => 'Published',
            self::STATUS_CANCELLED => 'Cancelled',
            default => $this->status,
        };
    }
}
