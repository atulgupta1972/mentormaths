<?php

namespace App\Support;

class ConceptPathStatus
{
    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DRAFT, self::APPROVED];
    }

    public static function label(?string $status): string
    {
        return match ($status) {
            self::APPROVED => 'Approved — ready for player',
            self::DRAFT => 'Draft — awaiting check',
            default => 'Not started',
        };
    }
}
