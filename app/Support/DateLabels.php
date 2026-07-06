<?php

namespace App\Support;

use Carbon\Carbon;

class DateLabels
{
    public static function formatDate(?string $value, ?string $fallback = null): ?string
    {
        if (! filled($value)) {
            return $fallback;
        }

        try {
            return Carbon::parse($value)->format('d M Y');
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public static function formatDateTime(?Carbon $value, ?string $fallback = null): ?string
    {
        if (! $value) {
            return $fallback;
        }

        return $value->format('d M Y, g:i A');
    }
}
