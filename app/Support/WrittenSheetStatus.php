<?php

namespace App\Support;

class WrittenSheetStatus
{
    public const DRAFT = 'draft';

    public const PENDING_REVIEW = 'pending_review';

    public const VERIFIED = 'verified';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DRAFT, self::PENDING_REVIEW, self::VERIFIED];
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING_REVIEW => 'Awaiting admin check',
            self::VERIFIED => 'Verified',
            default => 'Draft',
        };
    }
}
