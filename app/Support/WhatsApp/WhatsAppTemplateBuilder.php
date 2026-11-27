<?php

namespace App\Support\WhatsApp;

class WhatsAppTemplateBuilder
{
    /**
     * @return array{name: string, language: string, components: list<array<string, mixed>>}
     */
    public static function forChannel(string $channel, string $message, ?string $dashboardUrl = null): array
    {
        $templateName = (string) (
            config("whatsapp.templates.names.{$channel}")
            ?? config('whatsapp.templates.default_name', 'mentor_maths_update')
        );

        $language = (string) config('whatsapp.templates.language', 'en');
        $link = $dashboardUrl ?: (string) config('app.url');
        $body = self::compactBody($message, $link);

        return [
            'name' => $templateName,
            'language' => $language,
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $body],
                        ['type' => 'text', 'text' => $link],
                    ],
                ],
            ],
        ];
    }

    /**
     * Ten single-line values for mentor_maths_progress. The line breaks live in that template.
     *
     * @param  list<string>  $parameters
     * @return array{name: string, language: string, components: list<array<string, mixed>>}
     */
    public static function forProgressReport(array $parameters): array
    {
        return self::namedBodyParameters(
            (string) config('whatsapp.templates.progress_report_name', 'mentor_maths_progress'),
            $parameters,
            10,
        );
    }

    /**
     * Nine single-line values for mentor_maths_completed. Line breaks live in that template.
     *
     * @param  list<string>  $parameters
     * @return array{name: string, language: string, components: list<array<string, mixed>>}
     */
    public static function forAttemptCompletion(array $parameters): array
    {
        return self::namedBodyParameters(
            (string) config(
                'whatsapp.templates.names.assignment_completed',
                config('whatsapp.templates.completion_name', 'mentor_maths_completed'),
            ),
            $parameters,
            9,
        );
    }

    /**
     * @param  list<string>  $parameters
     * @return array{name: string, language: string, components: list<array<string, mixed>>}
     */
    private static function namedBodyParameters(string $name, array $parameters, int $count): array
    {
        $language = (string) config('whatsapp.templates.language', 'en');
        $values = array_slice(array_pad(array_values($parameters), $count, '—'), 0, $count);
        $bodyParameters = [];

        foreach ($values as $value) {
            $bodyParameters[] = [
                'type' => 'text',
                'text' => self::singleLine((string) $value),
            ];
        }

        return [
            'name' => $name,
            'language' => $language,
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => $bodyParameters,
                ],
            ],
        ];
    }

    public static function shouldUseTemplate(): bool
    {
        if (! config('whatsapp.templates.enabled', true)) {
            return false;
        }

        return (string) config('whatsapp.driver', 'manual') === 'meta';
    }

    private static function compactBody(string $message, string $link): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $message) ?: [];
        $filtered = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' && ($filtered === [] || end($filtered) === '')) {
                continue;
            }

            if (str_starts_with(strtolower($trimmed), 'view details:')) {
                break;
            }

            if ($trimmed === $link) {
                break;
            }

            $filtered[] = $line;
        }

        $body = self::singleLine(implode(' ', $filtered));
        $maxLen = (int) config('whatsapp.templates.body_max_length', 900);

        if (strlen($body) <= $maxLen) {
            return $body;
        }

        return substr($body, 0, $maxLen - 20).' … (truncated)';
    }

    public static function singleLine(string $value): string
    {
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
        $value = preg_replace('/ {2,}/', ' ', $value) ?? $value;
        $value = trim($value);

        return $value !== '' ? $value : '—';
    }
}
