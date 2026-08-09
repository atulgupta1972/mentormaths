<?php

namespace App\Support\WhatsApp;

class WhatsAppPhone
{
    /**
     * Normalize an Indian mobile for WhatsApp API (digits only, with country code).
     */
    public static function normalize(?string $mobile, ?string $defaultCountryCode = null): ?string
    {
        if (! filled($mobile)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        if ($digits === '') {
            return null;
        }

        $country = $defaultCountryCode ?? (string) config('whatsapp.default_country_code', '91');
        $country = ltrim($country, '+');

        if (strlen($digits) === 10) {
            return $country.$digits;
        }

        if (str_starts_with($digits, $country) && strlen($digits) >= strlen($country) + 10) {
            return $digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return $country.substr($digits, 1);
        }

        return $digits;
    }

    public static function isValid(?string $mobile): bool
    {
        $normalized = self::normalize($mobile);

        if (! $normalized) {
            return false;
        }

        $country = (string) config('whatsapp.default_country_code', '91');

        return (bool) preg_match('/^'.preg_quote($country, '/').'[6-9]\d{9}$/', $normalized);
    }
}
