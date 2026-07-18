<?php

namespace App\Support;

class ProgressSummaryChartImage
{
    /**
     * @param  list<array{label: string, percent: ?int}>  $series
     */
    public static function barChartDataUri(array $series, int $width = 500, int $height = 240): string
    {
        if ($series === [] || ! function_exists('imagecreatetruecolor')) {
            return '';
        }

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return '';
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 17, 24, 39);
        $gray = imagecolorallocate($img, 107, 114, 128);
        $grid = imagecolorallocate($img, 229, 231, 235);
        $bar = imagecolorallocate($img, 79, 70, 229);

        imagefill($img, 0, 0, $white);

        $margin = ['top' => 18, 'right' => 12, 'bottom' => 52, 'left' => 36];
        $plotWidth = $width - $margin['left'] - $margin['right'];
        $plotHeight = $height - $margin['top'] - $margin['bottom'];

        self::drawGrid($img, $margin, $plotWidth, $plotHeight, $grid, $gray);

        $count = count($series);
        $slotWidth = $plotWidth / max($count, 1);
        $barWidth = (int) min(42, $slotWidth * 0.62);

        foreach ($series as $index => $point) {
            $percent = max(0, min(100, (int) ($point['percent'] ?? 0)));
            $centerX = (int) ($margin['left'] + ($index + 0.5) * $slotWidth);
            $barHeight = (int) (($percent / 100) * $plotHeight);
            $barX = $centerX - (int) ($barWidth / 2);
            $barY = (int) ($margin['top'] + $plotHeight - max(1, $barHeight));

            imagefilledrectangle($img, $barX, $barY, $barX + $barWidth, $barY + max(1, $barHeight), $bar);

            imagestring($img, 2, $centerX - 12, max($margin['top'] + 4, $barY - 14), "{$percent}%", $black);
            imagestring(
                $img,
                1,
                max(4, $centerX - 24),
                $height - 18,
                self::truncate($point['label'] ?? '', 14),
                $black,
            );
        }

        return self::toDataUri($img);
    }

    /**
     * @param  list<array{label: string, percent: ?int}>  $series
     */
    public static function lineChartDataUri(array $series, int $width = 500, int $height = 240): string
    {
        if ($series === [] || ! function_exists('imagecreatetruecolor')) {
            return '';
        }

        $img = imagecreatetruecolor($width, $height);
        if ($img === false) {
            return '';
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 17, 24, 39);
        $gray = imagecolorallocate($img, 107, 114, 128);
        $grid = imagecolorallocate($img, 229, 231, 235);
        $line = imagecolorallocate($img, 5, 150, 105);

        imagefill($img, 0, 0, $white);

        $margin = ['top' => 18, 'right' => 12, 'bottom' => 52, 'left' => 36];
        $plotWidth = $width - $margin['left'] - $margin['right'];
        $plotHeight = $height - $margin['top'] - $margin['bottom'];

        self::drawGrid($img, $margin, $plotWidth, $plotHeight, $grid, $gray);

        $count = count($series);
        $points = [];

        foreach ($series as $index => $point) {
            $percent = max(0, min(100, (int) ($point['percent'] ?? 0)));
            $x = (int) ($margin['left'] + ($count === 1 ? $plotWidth / 2 : ($index / ($count - 1)) * $plotWidth));
            $y = (int) ($margin['top'] + $plotHeight - (($percent / 100) * $plotHeight));
            $points[] = ['x' => $x, 'y' => $y, 'label' => $point['label'] ?? '', 'percent' => $percent];
        }

        for ($i = 1; $i < count($points); $i++) {
            imageline(
                $img,
                $points[$i - 1]['x'],
                $points[$i - 1]['y'],
                $points[$i]['x'],
                $points[$i]['y'],
                $line,
            );
        }

        foreach ($points as $point) {
            imagefilledellipse($img, $point['x'], $point['y'], 8, 8, $line);
            imagestring($img, 2, $point['x'] - 12, max($margin['top'] + 4, $point['y'] - 16), "{$point['percent']}%", $black);
            imagestring(
                $img,
                1,
                max(4, $point['x'] - 20),
                $height - 18,
                self::truncate($point['label'], 12),
                $black,
            );
        }

        return self::toDataUri($img);
    }

    /**
     * @param  array{top: int, right: int, bottom: int, left: int}  $margin
     */
    private static function drawGrid($img, array $margin, int $plotWidth, int $plotHeight, int $gridColor, int $labelColor): void
    {
        foreach ([0, 25, 50, 75, 100] as $tick) {
            $y = (int) ($margin['top'] + $plotHeight - (($tick / 100) * $plotHeight));
            imageline($img, $margin['left'], $y, $margin['left'] + $plotWidth, $y, $gridColor);
            imagestring($img, 1, 8, $y - 4, (string) $tick, $labelColor);
        }
    }

    private static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * @param  \GdImage  $img
     */
    private static function toDataUri($img): string
    {
        ob_start();
        imagepng($img);
        $bytes = ob_get_clean();
        imagedestroy($img);

        if ($bytes === false || $bytes === '') {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
