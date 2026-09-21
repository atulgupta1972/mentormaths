<?php

namespace App\Support;

/**
 * Compare a source extract stem with a rewritten MentorMaths fill-blank stem.
 * High overlap / unchanged numbers → likely too close to the publisher original.
 */
class StemSimilarity
{
    /** Reject when Jaccard token overlap is at or above this (0–1). */
    public const DEFAULT_MAX_OVERLAP = 0.78;

    /** Numbers-unchanged only blocks when wording is also somewhat similar. */
    public const NUMBERS_UNCHANGED_MIN_OVERLAP = 0.55;

    /**
     * Publisher / brand phrases that must never appear in student-facing stems.
     *
     * @var list<string>
     */
    public const BANNED_PHRASES = [
        'rd sharma',
        'r.d. sharma',
        'r d sharma',
        'rs aggarwal',
        'r.s. aggarwal',
        'r s aggarwal',
        'rs agarwal',
        'r.s. agarwal',
        'grewal',
        'greya lakshmi',
        'lakshmi publications',
        'ncert exemplar',
        'ganita prakash',
    ];

    /**
     * @return array{
     *     overlap: float,
     *     shared_numbers: list<string>,
     *     banned_hit: ?string,
     *     too_similar: bool,
     *     numbers_unchanged: bool,
     *     reason: ?string
     * }
     */
    public function compare(string $sourceStem, string $rewrittenStem, float $maxOverlap = self::DEFAULT_MAX_OVERLAP): array
    {
        $bannedHit = $this->bannedPhraseIn($rewrittenStem);
        $overlap = $this->tokenJaccard($sourceStem, $rewrittenStem);
        $sourceNumbers = $this->significantNumbers($sourceStem);
        $rewrittenNumbers = $this->significantNumbers($rewrittenStem);
        $shared = array_values(array_intersect($sourceNumbers, $rewrittenNumbers));

        $numbersUnchanged = $sourceNumbers !== []
            && $rewrittenNumbers !== []
            && count($shared) === count($sourceNumbers)
            && count($shared) === count($rewrittenNumbers);

        // Unchanged numbers alone are OK if the stem was substantially rewritten.
        $numbersBlock = $numbersUnchanged && $overlap >= self::NUMBERS_UNCHANGED_MIN_OVERLAP;
        $tooSimilar = $bannedHit !== null || $overlap >= $maxOverlap || $numbersBlock;

        $reason = null;
        if ($bannedHit !== null) {
            $reason = "Stem still mentions publisher/brand wording (“{$bannedHit}”).";
        } elseif ($overlap >= $maxOverlap) {
            $reason = 'Stem is too close to the source extract (rewrite wording more thoroughly).';
        } elseif ($numbersBlock) {
            $reason = 'Numbers are unchanged from the source — change quantities so the answer changes.';
        }

        return [
            'overlap' => round($overlap, 3),
            'shared_numbers' => $shared,
            'banned_hit' => $bannedHit,
            'too_similar' => $tooSimilar,
            'numbers_unchanged' => $numbersUnchanged,
            'reason' => $reason,
        ];
    }

    public function bannedPhraseIn(string $text): ?string
    {
        $haystack = mb_strtolower($text);

        foreach (self::BANNED_PHRASES as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }

    public function tokenJaccard(string $a, string $b): float
    {
        $tokensA = $this->tokens($a);
        $tokensB = $this->tokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        $setA = array_unique($tokensA);
        $setB = array_unique($tokensB);
        $intersection = count(array_intersect($setA, $setB));
        $union = count(array_unique(array_merge($setA, $setB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * @return list<string>
     */
    public function significantNumbers(string $text): array
    {
        preg_match_all('/-?\d+(?:\.\d+)?(?:\s*\/\s*\d+(?:\.\d+)?)?/', $text, $matches);

        $numbers = [];
        foreach ($matches[0] ?? [] as $raw) {
            $compact = preg_replace('/\s+/', '', (string) $raw) ?? '';
            if ($compact === '' || $compact === '0') {
                continue;
            }
            // Skip lone year-like 4-digit numbers that are often incidental.
            if (preg_match('/^\d{4}$/', $compact) && (int) $compact >= 1900 && (int) $compact <= 2100) {
                continue;
            }
            $numbers[] = $compact;
        }

        sort($numbers);

        return array_values(array_unique($numbers));
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        $normalized = str_replace('____', ' ', $normalized);
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? '';
        $parts = preg_split('/\s+/', trim($normalized)) ?: [];

        $stop = [
            'a', 'an', 'the', 'of', 'to', 'in', 'on', 'at', 'for', 'and', 'or', 'is', 'are',
            'was', 'were', 'be', 'by', 'with', 'from', 'that', 'this', 'if', 'then', 'find',
            'what', 'which', 'when', 'how', 'many', 'much', 'value', 'equals', 'equal',
        ];

        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '' || in_array($part, $stop, true) || mb_strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }

        return $tokens;
    }
}
