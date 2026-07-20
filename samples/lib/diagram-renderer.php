<?php

/**
 * Crisp geometry diagrams for written-sheet zip packs.
 * Uses TrueType fonts + 2× resolution when available.
 */

function diagramFontPath(): ?string
{
    static $path = null;

    if ($path !== null) {
        return $path === '' ? null : $path;
    }

    $candidates = [
        dirname(__DIR__, 2).'/storage/fonts/DejaVuSans.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $path = $candidate;
        }
    }

    return $path = '';
}

function renderDiagram(string $path, string $type, array $params, int $number, string $topic): bool
{
    if (! function_exists('imagecreatetruecolor')) {
        return false;
    }

    $scale = 2;
    $canvas = newDiagramCanvas(520 * $scale, 280 * $scale, $scale);
    if ($canvas === null) {
        return false;
    }

    ['img' => $img, 'colors' => $c, 'scale' => $s] = $canvas;

    match ($type) {
        'intersect_opposite' => drawIntersectOpposite($img, $c, $params, $s),
        'linear_por' => drawLinearPor($img, $c, $params, $s),
        'linear_pair' => drawLinearPair($img, $c, $params, $s),
        'right_angle' => drawRightAngleIntersect($img, $c, $s),
        'intersect_adjacent' => drawIntersectAdjacent($img, $c, $params, $s),
        'perpendicular' => drawPerpendicular($img, $c, $s),
        'intersect_adjacent_x' => drawIntersectAdjacentX($img, $c, $params, $s),
        'parallel' => drawParallelTransversal($img, $c, $params, $s),
        'parallel_named' => drawParallelNamed($img, $c, $params, $s),
        default => drawIntersectOpposite($img, $c, ['angle' => 45, 'label' => ''], $s),
    };

    imagealphablending($img, false);
    imagesavealpha($img, true);

    $saved = str_ends_with(strtolower($path), '.png')
        ? imagepng($img, $path, 6)
        : imagejpeg($img, $path, 94);

    imagedestroy($img);

    return (bool) $saved;
}

function newDiagramCanvas(int $width, int $height, int $scale): ?array
{
    $img = imagecreatetruecolor($width, $height);
    if ($img === false) {
        return null;
    }

    if (function_exists('imageantialias')) {
        imageantialias($img, true);
    }

    $white = imagecolorallocatealpha($img, 255, 255, 255, 0);
    imagefill($img, 0, 0, $white);

    return [
        'img' => $img,
        'width' => $width,
        'height' => $height,
        'scale' => $scale,
        'colors' => [
            'black' => imagecolorallocate($img, 17, 24, 39),
            'blue' => imagecolorallocate($img, 29, 78, 216),
            'red' => imagecolorallocate($img, 185, 28, 28),
            'green' => imagecolorallocate($img, 21, 128, 61),
            'gray' => imagecolorallocate($img, 107, 114, 128),
            'highlight' => imagecolorallocate($img, 220, 38, 38),
        ],
    ];
}

function s(int $value, int $scale): int
{
    return $value * $scale;
}

function drawLabel($img, int $x, int $y, string $text, int $color, int $scale, int $size = 13, string $align = 'left'): void
{
    $font = diagramFontPath();

    if ($font) {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = abs($box[2] - $box[0]);
        $height = abs($box[7] - $box[1]);
        $drawX = match ($align) {
            'center' => $x - (int) ($width / 2),
            'right' => $x - $width,
            default => $x,
        };
        $drawY = $y + $height;

        imagettftext($img, $size, 0, $drawX, $drawY, $color, $font, $text);

        return;
    }

    $fontId = $size >= 14 ? 4 : 3;
    $drawX = match ($align) {
        'center' => $x - (int) (strlen($text) * 4),
        'right' => $x - (int) (strlen($text) * 8),
        default => $x,
    };
    imagestring($img, $fontId, $drawX, $y, $text, $color);
}

function drawLine($img, int $x1, int $y1, int $x2, int $y2, int $color, int $scale, int $thickness = 2): void
{
    imagesetthickness($img, max(1, $thickness * $scale));
    imageline($img, s($x1, $scale), s($y1, $scale), s($x2, $scale), s($y2, $scale), $color);
    imagesetthickness($img, 1);
}

function drawAngleArc($img, int $cx, int $cy, int $radius, float $startDeg, float $endDeg, int $color, int $scale): void
{
    imagearc(
        $img,
        s($cx, $scale),
        s($cy, $scale),
        s($radius * 2, $scale),
        s($radius * 2, $scale),
        (int) $startDeg,
        (int) $endDeg,
        $color,
    );
}

function drawIntersectOpposite($img, array $c, array $params, int $scale): void
{
    $ox = 260;
    $oy = 170;
    $angle = (float) ($params['angle'] ?? 45);
    $label = trim((string) ($params['label'] ?? ''));

    drawLine($img, 80, 90, 440, 250, $c['blue'], $scale);
    drawLine($img, 80, 250, 440, 90, $c['blue'], $scale);

    drawLabel($img, 68, 78, 'A', $c['black'], $scale, 14);
    drawLabel($img, 444, 252, 'B', $c['black'], $scale, 14);
    drawLabel($img, 68, 252, 'C', $c['black'], $scale, 14);
    drawLabel($img, 444, 78, 'D', $c['black'], $scale, 14);
    drawLabel($img, $ox - 6, $oy + 8, 'O', $c['black'], $scale, 14);

    drawAngleArc($img, $ox, $oy, 28, 360 - $angle, 360, $c['highlight'], $scale);
    $text = $label !== '' ? "{$label} = {$angle}°" : "{$angle}°";
    drawLabel($img, $ox + 24, $oy - 36, $text, $c['highlight'], $scale, 12);
}

function drawLinearPor($img, array $c, array $params, int $scale): void
{
    $angle = (float) ($params['angle'] ?? 47);
    $oy = 210;
    $ox = 260;

    drawLine($img, 60, $oy, 460, $oy, $c['blue'], $scale);
    $rx = (int) ($ox + 110 * cos(deg2rad(180 - $angle)));
    $ry = (int) ($oy - 110 * sin(deg2rad(180 - $angle)));
    drawLine($img, $ox, $oy, $rx, $ry, $c['blue'], $scale);

    drawLabel($img, 48, $oy + 4, 'P', $c['black'], $scale, 14);
    drawLabel($img, $ox - 6, $oy + 10, 'O', $c['black'], $scale, 14);
    drawLabel($img, 462, $oy + 4, 'Q', $c['black'], $scale, 14);
    drawLabel($img, $rx + 6, $ry - 16, 'R', $c['black'], $scale, 14);

    drawAngleArc($img, $ox, $oy, 32, 180 - $angle, 180, $c['highlight'], $scale);
    drawLabel($img, $ox - 72, $oy - 38, "POR = {$angle}°", $c['highlight'], $scale, 12);
}

function drawLinearPair($img, array $c, array $params, int $scale): void
{
    $angle = (float) ($params['angle'] ?? 112);
    $label = (string) ($params['label'] ?? 'A');
    $oy = 220;
    $ox = 260;

    drawLine($img, 60, $oy, 460, $oy, $c['blue'], $scale);
    drawLine($img, $ox, $oy, $ox, 80, $c['blue'], $scale);

    drawAngleArc($img, $ox, $oy, 34, 270, 360, $c['highlight'], $scale);
    drawLabel($img, $ox + 18, $oy - 48, "{$label} = {$angle}°", $c['highlight'], $scale, 12);
    drawLabel($img, $ox + 56, $oy - 6, 'B', $c['black'], $scale, 14);
    drawLabel($img, $ox - 10, $oy + 10, 'O', $c['black'], $scale, 14);
}

function drawRightAngleIntersect($img, array $c, int $scale): void
{
    $px = 260;
    $py = 170;

    drawLine($img, 110, 100, 410, 240, $c['blue'], $scale);
    drawLine($img, 110, 240, 410, 100, $c['blue'], $scale);

    drawLabel($img, 248, $py + 10, 'P', $c['black'], $scale, 14);
    drawLabel($img, 98, 88, 'l', $c['black'], $scale, 14);
    drawLabel($img, 418, 88, 'm', $c['black'], $scale, 14);

    $size = s(18, $scale);
    $x1 = s($px + 10, $scale);
    $y1 = s($py - 28, $scale);
    imagerectangle($img, $x1, $y1, $x1 + $size, $y1 + $size, $c['highlight']);
    drawLabel($img, $px + 36, $py - 38, '1 = 90°', $c['highlight'], $scale, 12);
}

function drawIntersectAdjacent($img, array $c, array $params, int $scale): void
{
    $angle = (float) ($params['angle'] ?? 36);
    $label = (string) ($params['label'] ?? 'AOC');
    $ox = 260;
    $oy = 170;

    drawLine($img, 80, 90, 440, 250, $c['blue'], $scale);
    drawLine($img, 80, 250, 440, 90, $c['blue'], $scale);

    drawLabel($img, 68, 78, 'A', $c['black'], $scale, 14);
    drawLabel($img, 444, 252, 'B', $c['black'], $scale, 14);
    drawLabel($img, 68, 252, 'C', $c['black'], $scale, 14);
    drawLabel($img, 444, 78, 'D', $c['black'], $scale, 14);
    drawLabel($img, $ox - 8, $oy + 10, 'O', $c['black'], $scale, 14);

    drawAngleArc($img, $ox, $oy, 28, 360 - $angle, 360, $c['highlight'], $scale);
    drawLabel($img, $ox + 20, $oy - 32, "{$label} = {$angle}°", $c['highlight'], $scale, 12);
}

function drawPerpendicular($img, array $c, int $scale): void
{
    $ox = 260;
    $oy = 190;

    drawLine($img, 90, $oy, 430, $oy, $c['blue'], $scale);
    drawLine($img, $ox, $oy, $ox, 70, $c['blue'], $scale);

    drawLabel($img, 82, $oy + 4, 'P', $c['black'], $scale, 14);
    drawLabel($img, $ox - 8, $oy + 10, 'O', $c['black'], $scale, 14);
    drawLabel($img, $ox + 6, 58, 'Q', $c['black'], $scale, 14);

    $size = s(18, $scale);
    $x1 = s($ox + 10, $scale);
    $y1 = s($oy - 28, $scale);
    imagerectangle($img, $x1, $y1, $x1 + $size, $y1 + $size, $c['highlight']);
    drawLabel($img, $ox + 36, $oy - 38, 'POQ = 90°', $c['highlight'], $scale, 12);
}

function drawIntersectAdjacentX($img, array $c, array $params, int $scale): void
{
    $angle = (float) ($params['angle'] ?? 71);
    $ox = 260;
    $oy = 170;

    drawLine($img, 80, $oy, 440, $oy, $c['blue'], $scale);
    drawLine($img, $ox, 70, $ox, 270, $c['blue'], $scale);

    drawAngleArc($img, $ox, $oy, 30, 270, 360 - (180 - $angle), $c['highlight'], $scale);
    drawLabel($img, $ox + 22, $oy - 44, "x = {$angle}°", $c['highlight'], $scale, 12);
}

function drawParallelTransversal($img, array $c, array $params, int $scale): void
{
    $highlight = $params['highlight'] ?? [1, 5];
    $values = $params['values'] ?? [];

    $yTop = 110;
    $yBottom = 230;
    $xLeft = 60;
    $xRight = 460;

    // Transversal: top-left to bottom-right
    $tx1 = 150;
    $ty1 = 50;
    $tx2 = 370;
    $ty2 = 290;

    drawLine($img, $xLeft, $yTop, $xRight, $yTop, $c['blue'], $scale, 2);
    drawLine($img, $xLeft, $yBottom, $xRight, $yBottom, $c['blue'], $scale, 2);
    drawLine($img, $tx1, $ty1, $tx2, $ty2, $c['blue'], $scale, 2);

    drawLabel($img, 42, $yTop - 10, 'l', $c['black'], $scale, 14);
    drawLabel($img, 42, $yBottom - 10, 'm', $c['black'], $scale, 14);
    drawLabel($img, 378, 42, 't', $c['black'], $scale, 14);

    // Intersection points
    $topX = 220;
    $topY = $yTop;
    $bottomX = 300;
    $bottomY = $yBottom;

    // Standard positions for angles 1–8 (offset from each intersection)
    $slots = [
        1 => [$topX - 52, $topY - 34],
        2 => [$topX + 18, $topY - 34],
        3 => [$topX + 18, $topY + 10],
        4 => [$topX - 52, $topY + 10],
        5 => [$bottomX - 52, $bottomY - 34],
        6 => [$bottomX + 18, $bottomY - 34],
        7 => [$bottomX + 18, $bottomY + 10],
        8 => [$bottomX - 52, $bottomY + 10],
    ];

    $arcRanges = [
        1 => [200, 270],
        2 => [270, 340],
        3 => [20, 70],
        4 => [110, 160],
        5 => [200, 250],
        6 => [290, 340],
        7 => [20, 70],
        8 => [110, 160],
    ];

    foreach ($slots as $num => [$lx, $ly]) {
        $isHighlight = in_array($num, $highlight, true);
        $value = $values[$num] ?? null;
        $color = $isHighlight ? $c['highlight'] : $c['gray'];
        $size = $isHighlight ? 12 : 11;

        if ($isHighlight && isset($arcRanges[$num])) {
            [$start, $end] = $arcRanges[$num];
            $cx = $num <= 4 ? $topX : $bottomX;
            $cy = $num <= 4 ? $topY : $bottomY;
            drawAngleArc($img, $cx, $cy, 22, $start, $end, $color, $scale);
        }

        if ($isHighlight && $value !== null) {
            $display = is_numeric($value)
                ? "{$num} = {$value}°"
                : "{$num} = {$value}°";
            $labelSize = strlen((string) $value) > 6 ? 10 : $size;
            drawLabel($img, $lx, $ly, $display, $color, $scale, $labelSize);
        } else {
            drawLabel($img, $lx + 8, $ly + 4, (string) $num, $color, $scale, $size, 'center');
        }
    }
}

function drawParallelNamed($img, array $c, array $params, int $scale): void
{
    $angle = (float) ($params['angle'] ?? 73);
    $yTop = 110;
    $yBottom = 230;

    drawLine($img, 60, $yTop, 460, $yTop, $c['blue'], $scale);
    drawLine($img, 60, $yBottom, 460, $yBottom, $c['blue'], $scale);
    drawLine($img, 150, 50, 370, 290, $c['blue'], $scale);

    drawLabel($img, 42, $yTop - 10, 'A', $c['black'], $scale, 14);
    drawLabel($img, 464, $yTop - 10, 'B', $c['black'], $scale, 14);
    drawLabel($img, 42, $yBottom - 10, 'C', $c['black'], $scale, 14);
    drawLabel($img, 464, $yBottom - 10, 'D', $c['black'], $scale, 14);
    drawLabel($img, 376, 42, 'E', $c['black'], $scale, 14);
    drawLabel($img, 146, 278, 'F', $c['black'], $scale, 14);

    drawLabel($img, 286, 132, "AEF = {$angle}°", $c['highlight'], $scale, 12);
    drawLabel($img, 248, 212, 'EFD = ?', $c['green'], $scale, 12);
}

function minimalPngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}
