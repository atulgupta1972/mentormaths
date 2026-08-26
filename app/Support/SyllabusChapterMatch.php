<?php

namespace App\Support;

use App\Models\SyllabusChapter;
use Illuminate\Support\Collection;

class SyllabusChapterMatch
{
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/^(ch(apter)?\.?\s*\d+[a-z]?\s*[-–:.]?\s*)/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }

    /**
     * @param  Collection<int, SyllabusChapter>|iterable<SyllabusChapter>  $homeChapters
     */
    public static function matchHomeChapterId(?SyllabusChapter $source, iterable $homeChapters): ?int
    {
        if (! $source) {
            return null;
        }

        $byName = [];
        $byHead = [];

        foreach ($homeChapters as $chapter) {
            $normalized = self::normalizeName((string) $chapter->name);
            if ($normalized !== '' && ! isset($byName[$normalized])) {
                $byName[$normalized] = (int) $chapter->id;
            }
            if ($chapter->chapter_head_id && ! isset($byHead[(int) $chapter->chapter_head_id])) {
                $byHead[(int) $chapter->chapter_head_id] = (int) $chapter->id;
            }
        }

        $normalized = self::normalizeName((string) $source->name);
        if ($normalized !== '' && isset($byName[$normalized])) {
            return $byName[$normalized];
        }

        if ($source->chapter_head_id && isset($byHead[(int) $source->chapter_head_id])) {
            return $byHead[(int) $source->chapter_head_id];
        }

        return null;
    }
}
