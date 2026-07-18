<?php

namespace App\Support;

class StudentWeeklyReportEmails
{
    /**
     * @return list<string>
     */
    public static function parse(?string $input, int $max = 2): array
    {
        if ($input === null || trim($input) === '') {
            return [];
        }

        $parts = preg_split('/[\s,;]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $email = strtolower(trim($part));

            if ($email === '' || in_array($email, $emails, true)) {
                continue;
            }

            $emails[] = $email;

            if (count($emails) >= $max) {
                break;
            }
        }

        return $emails;
    }

    public static function display(?string $parent1Email, ?string $parent2Email): string
    {
        return implode(', ', array_values(array_filter([
            trim((string) $parent1Email),
            trim((string) $parent2Email),
        ])));
    }
}
