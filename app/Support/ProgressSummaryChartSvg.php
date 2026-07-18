<?php

namespace App\Support;

class ProgressSummaryChartSvg
{
    /**
     * @param  list<array{label: string, percent: ?int}>  $series
     */
    public static function barChart(array $series, int $width = 500, int $height = 240): string
    {
        if ($series === []) {
            return '';
        }

        $margin = ['top' => 18, 'right' => 12, 'bottom' => 52, 'left' => 36];
        $plotWidth = $width - $margin['left'] - $margin['right'];
        $plotHeight = $height - $margin['top'] - $margin['bottom'];
        $count = count($series);
        $slotWidth = $plotWidth / max($count, 1);
        $barWidth = min(42, $slotWidth * 0.62);

        $parts = [
            self::svgOpen($width, $height),
            self::gridLines($margin, $plotWidth, $plotHeight),
        ];

        foreach ($series as $index => $point) {
            $percent = max(0, min(100, (int) ($point['percent'] ?? 0)));
            $centerX = $margin['left'] + ($index + 0.5) * $slotWidth;
            $barX = $centerX - ($barWidth / 2);
            $barHeight = ($percent / 100) * $plotHeight;
            $barY = $margin['top'] + $plotHeight - $barHeight;

            $parts[] = sprintf(
                '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="#4f46e5" rx="2"/>',
                $barX,
                $barY,
                $barWidth,
                max(1, $barHeight),
            );

            $parts[] = sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" font-size="9" fill="#111827">%s%%</text>',
                $centerX,
                max($margin['top'] + 10, $barY - 4),
                $percent,
            );

            $parts[] = sprintf(
                '<text x="%.1f" y="%d" text-anchor="middle" font-size="8" fill="#374151">%s</text>',
                $centerX,
                $height - 8,
                self::escape(self::truncate($point['label'] ?? '', 16)),
            );
        }

        $parts[] = self::svgClose();

        return implode('', $parts);
    }

    /**
     * @param  list<array{label: string, percent: ?int}>  $series
     */
    public static function lineChart(array $series, int $width = 500, int $height = 240): string
    {
        if ($series === []) {
            return '';
        }

        $margin = ['top' => 18, 'right' => 12, 'bottom' => 52, 'left' => 36];
        $plotWidth = $width - $margin['left'] - $margin['right'];
        $plotHeight = $height - $margin['top'] - $margin['bottom'];
        $count = count($series);
        $points = [];

        foreach ($series as $index => $point) {
            $percent = max(0, min(100, (int) ($point['percent'] ?? 0)));
            $x = $margin['left'] + ($count === 1 ? $plotWidth / 2 : ($index / ($count - 1)) * $plotWidth);
            $y = $margin['top'] + $plotHeight - (($percent / 100) * $plotHeight);
            $points[] = ['x' => $x, 'y' => $y, 'label' => $point['label'] ?? '', 'percent' => $percent];
        }

        $polyline = collect($points)
            ->map(fn (array $point) => sprintf('%.1f,%.1f', $point['x'], $point['y']))
            ->implode(' ');

        $parts = [
            self::svgOpen($width, $height),
            self::gridLines($margin, $plotWidth, $plotHeight),
            sprintf('<polyline points="%s" fill="none" stroke="#059669" stroke-width="2"/>', $polyline),
        ];

        foreach ($points as $point) {
            $parts[] = sprintf(
                '<circle cx="%.1f" cy="%.1f" r="3.5" fill="#059669"/>',
                $point['x'],
                $point['y'],
            );
            $parts[] = sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" font-size="9" fill="#111827">%d%%</text>',
                $point['x'],
                max($margin['top'] + 10, $point['y'] - 8),
                $point['percent'],
            );
            $parts[] = sprintf(
                '<text x="%.1f" y="%d" text-anchor="middle" font-size="8" fill="#374151">%s</text>',
                $point['x'],
                $height - 8,
                self::escape(self::truncate($point['label'], 12)),
            );
        }

        $parts[] = self::svgClose();

        return implode('', $parts);
    }

    /**
     * @param  array{top: int, right: int, bottom: int, left: int}  $margin
     */
    private static function gridLines(array $margin, int $plotWidth, int $plotHeight): string
    {
        $lines = [];

        foreach ([0, 25, 50, 75, 100] as $tick) {
            $y = $margin['top'] + $plotHeight - (($tick / 100) * $plotHeight);
            $lines[] = sprintf(
                '<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e5e7eb" stroke-width="1"/>',
                $margin['left'],
                $y,
                $margin['left'] + $plotWidth,
                $y,
            );
            $lines[] = sprintf(
                '<text x="%d" y="%.1f" text-anchor="end" font-size="8" fill="#6b7280">%d</text>',
                $margin['left'] - 4,
                $y + 3,
                $tick,
            );
        }

        return implode('', $lines);
    }

    private static function svgOpen(int $width, int $height): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">',
            $width,
            $height,
            $width,
            $height,
        );
    }

    private static function svgClose(): string
    {
        return '</svg>';
    }

    private static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
