<?php

namespace App\Support;

/**
 * Internal-only source refs for MentorMaths practice books (never shown to students).
 */
class MentorMathsSourceRef
{
    /**
     * @return list<array{value: string, label: string, publisher: string}>
     */
    public static function options(?int $classNumber = null): array
    {
        $classes = $classNumber !== null && $classNumber > 0
            ? [$classNumber]
            : range(4, 10);

        $publishers = [
            ['code' => 'RDS', 'label' => 'RD Sharma'],
            ['code' => 'RSA', 'label' => 'RS Aggarwal'],
            ['code' => 'GL', 'label' => 'Greya Lakshmi'],
            ['code' => 'EXEM', 'label' => 'NCERT Exemplar'],
        ];

        $options = [];

        foreach ($classes as $class) {
            foreach ($publishers as $publisher) {
                $value = "{$publisher['code']}-C{$class}";
                $options[] = [
                    'value' => $value,
                    'label' => "{$value} — {$publisher['label']} Class {$class}",
                    'publisher' => $publisher['label'],
                ];
            }
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public static function values(?int $classNumber = null): array
    {
        return array_column(self::options($classNumber), 'value');
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return true;
        }

        return in_array(trim($value), self::values(), true);
    }

    /**
     * Guess a source ref from an existing publisher book name/code + class.
     */
    public static function guessFromBook(string $name, string $code, ?int $classNumber): ?string
    {
        if ($classNumber === null || $classNumber < 1) {
            return null;
        }

        $haystack = mb_strtolower(trim($name.' '.$code));

        $prefix = match (true) {
            str_contains($haystack, 'sharma') || $code === 'rds' => 'RDS',
            str_contains($haystack, 'aggarwal')
                || str_contains($haystack, 'agarwal')
                || $code === 'rs' => 'RSA',
            str_contains($haystack, 'lakshmi')
                || str_contains($haystack, 'greya')
                || $code === 'gl' => 'GL',
            str_contains($haystack, 'exemplar') || $code === 'exem' => 'EXEM',
            default => null,
        };

        return $prefix ? "{$prefix}-C{$classNumber}" : null;
    }
}
