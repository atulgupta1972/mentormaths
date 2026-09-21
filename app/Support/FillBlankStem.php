<?php

namespace App\Support;

/**
 * Normalize fill-blank stems so Gemini variants (___ / ______ / …) still apply.
 */
class FillBlankStem
{
    /**
     * Ensure the stem contains a canonical "____" blank when a placeholder is present.
     */
    public static function normalize(string $questionText): string
    {
        $text = trim($questionText);

        if ($text === '') {
            return $text;
        }

        // Collapse any run of 3+ underscores to the canonical blank.
        $replaced = preg_replace('/_{3,}/', '____', $text);
        if (is_string($replaced) && str_contains($replaced, '____')) {
            return $replaced;
        }

        // Ellipsis used as blank
        $replaced = preg_replace('/(?:\.{3}|…)/u', '____', $text, 1);
        if (is_string($replaced) && str_contains($replaced, '____')) {
            return $replaced;
        }

        // Common bracket placeholders
        $replaced = preg_replace('/\[\s*(?:blank|\?|_+)\s*\]/iu', '____', $text, 1);
        if (is_string($replaced) && str_contains($replaced, '____')) {
            return $replaced;
        }

        return $text;
    }

    public static function hasBlank(string $questionText): bool
    {
        return str_contains(self::normalize($questionText), '____');
    }
}
