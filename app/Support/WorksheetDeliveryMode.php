<?php

namespace App\Support;

class WorksheetDeliveryMode
{
    public const ONLINE = 'online';

    public const WRITTEN = 'written';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ONLINE, self::WRITTEN];
    }

    public static function label(string $mode): string
    {
        return match ($mode) {
            self::WRITTEN => 'Written homework',
            default => 'Online',
        };
    }
}
