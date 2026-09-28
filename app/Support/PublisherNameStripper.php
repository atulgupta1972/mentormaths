<?php

namespace App\Support;

/**
 * Removes leftover RD Sharma / RS Aggarwal names from stored question text.
 */
class PublisherNameStripper
{
    public function strip(string $text): string
    {
        $next = $text;
        $patterns = [
            '/\b(?:in|from|based on|as in|see)\s+(?:R\.?\s*D\.?\s*Sharma|R\.?\s*S\.?\s*Aggarwal|R\.?\s*S\.?\s*Agarwal)\b\s*[,:]?\s*/iu',
            '/\bR\.?\s*D\.?\s*Sharma\b\s*[:,]?\s*/iu',
            '/\bR\.?\s*S\.?\s*Aggarwal\b\s*[:,]?\s*/iu',
            '/\bR\.?\s*S\.?\s*Agarwal\b\s*[:,]?\s*/iu',
        ];

        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '', $next);
            if (is_string($replaced)) {
                $next = $replaced;
            }
        }

        $collapsed = preg_replace('/[ \t]{2,}/', ' ', $next);

        return is_string($collapsed) ? $collapsed : $next;
    }

    public function changed(string $text): bool
    {
        return $this->strip($text) !== $text;
    }
}
