<?php

namespace App\Support;

class TopicName
{
    /**
     * Key used to match import/JSON topic labels to syllabus topics.
     * Accepts LaTeX-ish ($a^2$) and Unicode (a²), and ignores trailing commas.
     */
    public static function normalize(?string $name): string
    {
        $text = trim((string) $name);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\$([^$]*)\$/u', '$1', $text) ?? $text;
        $text = str_replace(['^2', '^3'], ['²', '³'], $text);
        $text = preg_replace('/\$+/u', '', $text) ?? $text;
        $text = rtrim($text, " \t,");
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strtolower(trim($text));
    }

    /**
     * Student/admin-facing label without raw $a^2$ markup.
     */
    public static function display(?string $name): string
    {
        $text = trim((string) $name);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\$([^$]*)\$/u', '$1', $text) ?? $text;
        $text = str_replace(['^2', '^3'], ['²', '³'], $text);
        $text = preg_replace('/\$+/u', '', $text) ?? $text;
        $text = rtrim($text, " \t,");
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
