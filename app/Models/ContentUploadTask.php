<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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

    public const WORK_TYPE_MCQ_UPLOAD = 'mcq_upload';

    public const WORK_TYPE_FILL_BLANK_CONVERSION = 'fill_blank_conversion';

    protected $fillable = [
        'textbook_chapter_id',
        'work_type',
        'assigned_to_user_id',
        'assigned_by_user_id',
        'status',
        'rate_basis',
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

    private ?int $resolvedQuestionCount = null;

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

    public function questionDeleteRequests(): HasMany
    {
        return $this->hasMany(ContentQuestionDeleteRequest::class);
    }

    public function questionCorrections(): HasMany
    {
        return $this->hasMany(ContentQuestionCorrection::class);
    }

    public function isLockedForUploaderDelete(): bool
    {
        return $this->published_at !== null
            || in_array($this->status, [
                self::STATUS_SUBMITTED_FOR_PUBLISH,
                self::STATUS_PUBLISHED,
            ], true);
    }

    public function payableAmountInr(): int
    {
        $unit = $this->rateUnitInr();

        if ($unit <= 0) {
            return 0;
        }

        if ($this->rate_basis === ContentRateCard::BASIS_PER_QUESTION) {
            return $unit * $this->payableQuestionCount();
        }

        return $unit;
    }

    public function rateUnitInr(): int
    {
        return (int) ($this->agreed_amount_inr ?? $this->offered_amount_inr ?? 0);
    }

    /**
     * Questions that count toward uploader pay (uploaded minus skipped-as-irrelevant).
     */
    public function payableQuestionCount(): int
    {
        $uploaded = $this->uploadedQuestionCount();
        $skipped = $this->skippedQuestionCount();

        return max(0, $uploaded - $skipped);
    }

    public function skippedQuestionCount(): int
    {
        $this->loadMissing('verificationRuns');

        $runIds = $this->verificationRuns->pluck('id');

        if ($runIds->isEmpty()) {
            return 0;
        }

        return (int) ContentVerificationCheck::query()
            ->whereIn('content_verification_run_id', $runIds)
            ->where('skipped', true)
            ->distinct()
            ->count('question_id');
    }

    public function uploadedQuestionCount(): int
    {
        if ($this->resolvedQuestionCount !== null) {
            return $this->resolvedQuestionCount;
        }

        $this->loadMissing('textbookChapter');
        $chapter = $this->textbookChapter;

        if (! $chapter) {
            return $this->resolvedQuestionCount = 0;
        }

        $worksheetIds = $chapter->mcqWorksheetIds();

        if ($chapter->fill_blank_worksheet_id) {
            $worksheetIds[] = (int) $chapter->fill_blank_worksheet_id;
            $worksheetIds = array_values(array_unique($worksheetIds));
        }

        if ($worksheetIds !== []) {
            $fromWorksheets = (int) DB::table('worksheet_question')
                ->whereIn('worksheet_id', $worksheetIds)
                ->distinct()
                ->count('question_id');

            if ($fromWorksheets > 0) {
                return $this->resolvedQuestionCount = $fromWorksheets;
            }
        }

        $items = $chapter->extraction_items ?? [];

        return $this->resolvedQuestionCount = is_array($items) ? count($items) : 0;
    }

    public function rateBasisLabel(): string
    {
        return ContentRateCard::basisLabel($this->rate_basis);
    }

    public function rateAgreedLabel(): string
    {
        $unit = $this->rateUnitInr();

        if ($this->rate_basis === ContentRateCard::BASIS_PER_QUESTION) {
            return '₹'.number_format($unit).' per question';
        }

        return '₹'.number_format($unit).' per chapter';
    }

    public function calculationLabel(): string
    {
        $unit = $this->rateUnitInr();

        if ($this->rate_basis !== ContentRateCard::BASIS_PER_QUESTION) {
            return '₹'.number_format($unit).' per chapter';
        }

        $payable = $this->payableQuestionCount();
        $skipped = $this->skippedQuestionCount();
        $total = $this->payableAmountInr();

        if ($payable <= 0 && $skipped <= 0) {
            return 'No questions counted yet — ₹0 until questions are uploaded';
        }

        $label = $payable.' payable × ₹'.number_format($unit).' = ₹'.number_format($total);

        if ($skipped > 0) {
            $label .= ' ('.$skipped.' skipped — not paid)';
        }

        return $label;
    }

    public function rateDescription(): string
    {
        if ($this->rate_basis === ContentRateCard::BASIS_PER_QUESTION) {
            $payable = $this->payableQuestionCount();
            $skipped = $this->skippedQuestionCount();
            $total = $this->payableAmountInr();

            if ($payable > 0 || $skipped > 0) {
                $label = $this->rateAgreedLabel().' × '.$payable.' = ₹'.number_format($total);
                if ($skipped > 0) {
                    $label .= ' · '.$skipped.' skipped (not paid)';
                }

                return $label;
            }

            return $this->rateAgreedLabel();
        }

        return $this->rateAgreedLabel();
    }

    public function isPayable(): bool
    {
        return in_array($this->status, [
            self::STATUS_VERIFIED,
            self::STATUS_SUBMITTED_FOR_PUBLISH,
            self::STATUS_PUBLISHED,
        ], true) && $this->payableAmountInr() > 0;
    }

    public function isFillBlankConversion(): bool
    {
        return ($this->work_type ?: self::WORK_TYPE_MCQ_UPLOAD) === self::WORK_TYPE_FILL_BLANK_CONVERSION;
    }

    public function workTypeLabel(): string
    {
        return $this->isFillBlankConversion()
            ? 'Fill-in-blank + written'
            : 'MCQ upload';
    }

    public function isAwaitingAgreement(): bool
    {
        return $this->status === self::STATUS_PENDING_AGREEMENT;
    }

    public function canReassign(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_SUBMITTED_FOR_PUBLISH,
            self::STATUS_PUBLISHED,
            self::STATUS_CANCELLED,
        ], true);
    }

    public function canAssigneeWork(): bool
    {
        return in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_UPLOADED,
            self::STATUS_VERIFICATION_IN_PROGRESS,
            self::STATUS_VERIFIED,
            self::STATUS_SUBMITTED_FOR_PUBLISH,
        ], true);
    }

    /**
     * Uploader dashboard bucket: upload_pending | review_pending | done
     */
    public function uploaderBucket(): string
    {
        if ($this->isFillBlankConversion()) {
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

            return 'convert_pending';
        }

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
            'upload_pending' => $this->isFillBlankConversion() ? 'Agree rate' : 'Upload pending',
            'review_pending' => 'Review pending',
            'convert_pending' => 'Fill-in-blank conversion',
            default => 'Complete',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING_AGREEMENT => 'Awaiting rate agreement',
            self::STATUS_IN_PROGRESS => $this->isFillBlankConversion()
                ? 'Convert fill-in-blanks'
                : 'In progress',
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
