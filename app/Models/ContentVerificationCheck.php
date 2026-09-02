<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentVerificationCheck extends Model
{
    /** @var list<string> */
    public const CHECK_FIELDS = [
        'check_text',
        'check_options',
        'check_correct',
        'check_hint',
        'check_explanation',
        'check_difficulty',
        'check_diagram',
    ];

    protected $fillable = [
        'content_verification_run_id',
        'question_id',
        'check_text',
        'check_options',
        'check_correct',
        'check_hint',
        'check_explanation',
        'check_difficulty',
        'check_diagram',
        'diagram_note',
        'skipped',
        'skip_reason',
        'skipped_at',
        'ai_verdict',
        'ai_confidence',
        'ai_note',
        'ai_reviewed_at',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'check_text' => 'boolean',
            'check_options' => 'boolean',
            'check_correct' => 'boolean',
            'check_hint' => 'boolean',
            'check_explanation' => 'boolean',
            'check_difficulty' => 'boolean',
            'check_diagram' => 'boolean',
            'skipped' => 'boolean',
            'verified_at' => 'datetime',
            'skipped_at' => 'datetime',
            'ai_reviewed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentVerificationRun::class, 'content_verification_run_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /**
     * @return list<string>
     */
    public static function requiredFieldsFor(Question $question): array
    {
        if ($question->isFillInBlank()) {
            return [
                'check_text',
                'check_correct',
                'check_hint',
                'check_explanation',
                'check_difficulty',
                'check_diagram',
            ];
        }

        return self::CHECK_FIELDS;
    }

    /** Verified for upload, or deliberately skipped (irrelevant — not paid). */
    public function isComplete(): bool
    {
        if ($this->skipped) {
            return true;
        }

        foreach (self::CHECK_FIELDS as $field) {
            if (! $this->{$field}) {
                return false;
            }
        }

        return true;
    }

    public function isCompleteFor(Question $question): bool
    {
        if ($this->skipped) {
            return true;
        }

        foreach (self::requiredFieldsFor($question) as $field) {
            if (! $this->{$field}) {
                return false;
            }
        }

        return true;
    }

    public function countsForPay(): bool
    {
        return ! $this->skipped;
    }
}
