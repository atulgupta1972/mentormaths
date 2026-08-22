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

        $body = trim(implode("\n", $filtered));
        $maxLen = (int) config('whatsapp.templates.body_max_length', 900);

        if (strlen($body) <= $maxLen) {
            return $body;
        }

        return substr($body, 0, $maxLen - 20)."\n\n… (truncated)";
    }
}
