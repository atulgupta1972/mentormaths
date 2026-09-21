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

    /**
     * Force a blank into the stem when Gemini forgot ____.
     * Tries replacing the known answer first, otherwise appends " is ____.".
     */
    public static function ensureBlank(string $questionText, ?string $answer = null): string
    {
        $text = self::normalize($questionText);

        if (self::hasBlank($text)) {
            return $text;
        }

        $answer = trim((string) $answer);
        if ($answer !== '') {
            $escaped = preg_quote($answer, '/');
            $replaced = preg_replace('/(?<![\d\/.])'.$escaped.'(?![\d\/.])/u', '____', $text, 1);
            if (is_string($replaced) && self::hasBlank($replaced)) {
                return $replaced;
            }

            // Fraction / decimal soft match without strict boundaries
            $replaced = preg_replace('/'.preg_quote($answer, '/').'/u', '____', $text, 1);
            if (is_string($replaced) && self::hasBlank($replaced) && $replaced !== $text) {
                return $replaced;
            }
        }

        $trimmed = rtrim($text, " \t");
        $trimmed = rtrim($trimmed, '.?!');

        return $trimmed.' is ____.';
    }
}
