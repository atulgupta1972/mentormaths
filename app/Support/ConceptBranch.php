<?php

namespace App\Support;

class ConceptBranch
{
    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(config('concept_branches.order', [self::OTHER]));
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return config('concept_branches.labels', [self::OTHER => 'Other']);
    }

    public static function label(?string $key): string
    {
        $key = self::normalize($key);

        return self::labels()[$key] ?? self::labels()[self::OTHER] ?? 'Other';
    }

    public static function normalize(?string $key): string
    {
        $key = strtolower(trim((string) $key));

        return in_array($key, self::keys(), true) ? $key : self::OTHER;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'label' => self::label($key)],
            self::keys(),
        );
    }

    /**
     * Guess a branch from a chapter-head name (used for backfill / quick create).
     */
    public static function guessFromName(string $name): string
    {
        $n = mb_strtolower(trim($name));

        // Algebra before Geometry so "Linear Equations" is not caught by a bare "line" needle.
        $rules = [
            'trigonometry' => ['trigo', 'trigonometr', 'sin ', 'cos ', 'tan ', 'identit'],
            'mensuration' => ['mensurat', 'surface area', 'volume', 'perimeter', 'area and', 'areas and'],
            'algebra' => [
                'algebra', 'equation', 'expression', 'linear', 'quadratic', 'polynomial',
                'factoris', 'factoriz', 'identity', 'inequalit', 'simultaneous',
            ],
            'geometry' => [
                'geometr', 'triangle', 'circle', 'quadrilateral', 'line segment', 'straight line',
                'lines and', 'angles', 'angle ',
                'construction', 'congruen', 'similar', 'coordinate', 'locus', 'symmetry',
                'polyhedra', 'polygon', 'parallel', 'transversal',
            ],
            'statistics' => ['statist', 'data handl', 'probability', 'graph', 'pie chart', 'mean', 'median', 'mode'],
            'commercial' => [
                'percent', 'profit', 'loss', 'simple interest', 'compound interest',
                'ratio', 'proportion', 'commercial', 'banking', 'gst', 'discount',
            ],
            'number_system' => [
                'number system', 'integers', 'rational', 'irrational', 'real number',
                'natural number', 'whole number', 'prime', 'hcf', 'lcm', 'fraction', 'decimal',
            ],
            'arithmetic' => [
                'arithmetic', 'operation', 'bodmas', 'estimation', 'rounding',
                'square root', 'cube root', 'exponents', 'powers', 'playing with numbers',
            ],
        ];

        foreach ($rules as $branch => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($n, $needle)) {
                    return $branch;
                }
            }
        }

        return self::OTHER;
    }
}
