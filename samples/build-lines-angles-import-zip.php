<?php

/**
 * Build samples/lines-angles-ch5-import.zip for testing zip import.
 * Run: php samples/build-lines-angles-import-zip.php
 */

$root = dirname(__DIR__);
$buildDir = $root.'/samples/lines-angles-ch5-pack';
$zipPath = $root.'/samples/lines-angles-ch5-import.zip';

$questions = [
    ['topic' => 'Related Angles', 'question' => 'In the figure, lines AB and CD intersect at O. If ∠AOC = 58°, then the vertically opposite angle ∠BOD = ____°.', 'answer' => '58', 'hint' => 'Vertically opposite angles formed by two intersecting lines are equal.', 'explanation' => '∠AOC and ∠BOD are vertically opposite angles, so ∠BOD = 58°.', 'difficulty' => 'Medium', 'diagram' => 'intersect_opposite', 'params' => ['angle' => 58, 'label' => 'AOC']],
    ['topic' => 'Related Angles', 'question' => 'In the figure, POQ is a straight line. If ∠POR = 47°, then ∠ROQ = ____°.', 'answer' => '133', 'hint' => 'Angles forming a linear pair on a straight line add up to 180°.', 'explanation' => '∠ROQ = 180° − 47° = 133°.', 'difficulty' => 'Medium', 'diagram' => 'linear_por', 'params' => ['angle' => 47]],
    ['topic' => 'Related Angles', 'question' => 'In the figure, ∠A and ∠B form a linear pair. If ∠A = 112°, then ∠B = ____°.', 'answer' => '68', 'hint' => 'A linear pair sums to 180°.', 'explanation' => '∠B = 180° − 112° = 68°.', 'difficulty' => 'Medium', 'diagram' => 'linear_pair', 'params' => ['angle' => 112, 'label' => 'A']],
    ['topic' => 'Related Angles', 'question' => 'In the figure, two lines intersect. If one angle is 39°, then its vertically opposite angle is ____°.', 'answer' => '39', 'hint' => 'Vertically opposite angles are equal.', 'explanation' => 'The vertically opposite angle is 39°.', 'difficulty' => 'Medium', 'diagram' => 'intersect_opposite', 'params' => ['angle' => 39, 'label' => '']],
    ['topic' => 'Pairs of Lines', 'question' => 'In the figure, lines l and m intersect at P. If ∠1 = 90°, then ∠1 is a ____° angle.', 'answer' => '90', 'hint' => 'An angle of 90° is called a right angle.', 'explanation' => 'The angle marked is 90°.', 'difficulty' => 'Medium', 'diagram' => 'right_angle', 'params' => []],
    ['topic' => 'Pairs of Lines', 'question' => 'In the figure, lines AB and CD intersect at O. If ∠AOC = 36°, then ∠AOD = ____°.', 'answer' => '144', 'hint' => 'Adjacent angles on a straight line form a linear pair and sum to 180°.', 'explanation' => '∠AOD = 180° − 36° = 144°.', 'difficulty' => 'Medium', 'diagram' => 'intersect_adjacent', 'params' => ['angle' => 36, 'label' => 'AOC']],
    ['topic' => 'Pairs of Lines', 'question' => 'In the figure, OP ⊥ OQ. The measure of ∠POQ is ____°.', 'answer' => '90', 'hint' => 'Perpendicular lines intersect at 90°.', 'explanation' => 'OP ⟂ OQ, so ∠POQ = 90°.', 'difficulty' => 'Medium', 'diagram' => 'perpendicular', 'params' => []],
    ['topic' => 'Pairs of Lines', 'question' => 'In the figure, lines intersect and ∠x = 71°. The angle adjacent to ∠x on the straight line is ____°.', 'answer' => '109', 'hint' => 'Adjacent angles on a straight line add to 180°.', 'explanation' => 'Required angle = 180° − 71° = 109°.', 'difficulty' => 'Medium', 'diagram' => 'intersect_adjacent_x', 'params' => ['angle' => 71]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m and t is a transversal. If ∠1 = 65° (corresponding angles), then ∠5 = ____°.', 'answer' => '65', 'hint' => 'Corresponding angles are equal when lines are parallel.', 'explanation' => '∠5 = 65°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [1, 5], 'values' => [1 => 65, 5 => 65]]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m and line t is a transversal. If ∠3 = 52° (alternate interior angles), then ∠6 = ____°.', 'answer' => '52', 'hint' => 'Alternate interior angles are equal for parallel lines.', 'explanation' => '∠6 = 52°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [3, 6], 'values' => [3 => 52, 6 => 52]]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m. If ∠4 = 118° (co-interior angles), then ∠5 = ____°.', 'answer' => '62', 'hint' => 'Co-interior angles are supplementary when lines are parallel.', 'explanation' => '∠5 = 180° − 118° = 62°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [4, 5], 'values' => [4 => 118, 5 => 62]]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m and t is a transversal. If ∠2 = 44° (corresponding angles), then ∠6 = ____°.', 'answer' => '44', 'hint' => 'Corresponding angles are equal when the lines are parallel.', 'explanation' => '∠6 = 44°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [2, 6], 'values' => [2 => 44, 6 => 44]]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, AB ∥ CD and EF is a transversal. If ∠AEF = 73°, then the alternate interior angle ∠EFD = ____°.', 'answer' => '73', 'hint' => 'Alternate interior angles are equal between parallel lines.', 'explanation' => '∠EFD = 73°.', 'difficulty' => 'Hard', 'diagram' => 'parallel_named', 'params' => ['angle' => 73]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m. The co-interior angles on one side of transversal t are 105° and x. Then x = ____°.', 'answer' => '75', 'hint' => 'Co-interior angles between parallel lines add up to 180°.', 'explanation' => 'x = 180° − 105° = 75°.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [4, 5], 'values' => [4 => 105, 5 => 'x']]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m. If an acute angle formed by the transversal is 28°, then another acute angle in the alternate interior position is ____°.', 'answer' => '28', 'hint' => 'Alternate interior angles are equal when lines are parallel.', 'explanation' => 'The alternate interior acute angle is 28°.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [3, 6], 'values' => [3 => 28, 6 => 28]]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m. If ∠7 = 132°, then the co-interior angle ∠4 on the same side of the transversal is ____°.', 'answer' => '48', 'hint' => 'Co-interior angles are supplementary for parallel lines.', 'explanation' => '∠4 = 180° − 132° = 48°.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [4, 7], 'values' => [7 => 132, 4 => 48]]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, lines l and m are cut by transversal t. If corresponding angles ∠1 and ∠5 are both 81°, then ∠1 − ∠5 = ____°.', 'answer' => '0', 'hint' => 'Equal corresponding angles mean the lines are parallel.', 'explanation' => '81° − 81° = 0°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [1, 5], 'values' => [1 => 81, 5 => 81]]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, transversal t cuts lines l and m. If alternate interior angles are 54° and x, and the lines are parallel, then x = ____°.', 'answer' => '54', 'hint' => 'Parallel lines give equal alternate interior angles.', 'explanation' => 'x = 54°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [3, 6], 'values' => [3 => 54, 6 => 'x']]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, corresponding angles measure 76° and 76°. The common measure is ____°.', 'answer' => '76', 'hint' => 'Equal corresponding angles indicate parallel lines.', 'explanation' => 'Both angles are 76°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [1, 5], 'values' => [1 => 76, 5 => 76]]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, co-interior angles are 111° and y. If the lines are parallel, then y = ____°.', 'answer' => '69', 'hint' => 'Co-interior angles are supplementary when lines are parallel.', 'explanation' => 'y = 180° − 111° = 69°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [4, 5], 'values' => [4 => 111, 5 => 'y']]],
];

if (is_dir($buildDir)) {
    array_map('unlink', glob($buildDir.'/*') ?: []);
} else {
    mkdir($buildDir, 0755, true);
}

$jsonQuestions = [];
foreach ($questions as $index => $q) {
    $n = $index + 1;
    $jsonQuestions[] = [
        'topic' => $q['topic'],
        'question' => $q['question'],
        'diagram_file' => "q{$n}.jpg",
        'answer_format' => 'integer',
        'correct_answer' => $q['answer'],
        'method_hint' => $q['hint'],
        'explanation' => $q['explanation'],
        'difficulty' => $q['difficulty'],
    ];

    $imagePath = "{$buildDir}/q{$n}.jpg";
    if (! renderDiagram($imagePath, $q['diagram'], $q['params'] ?? [], $n, $q['topic'])) {
        file_put_contents($imagePath, minimalJpegBytes());
    }
}

file_put_contents(
    "{$buildDir}/questions.json",
    json_encode(['questions' => $jsonQuestions], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
);

if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive;
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create zip at {$zipPath}\n");
    exit(1);
}

foreach (glob("{$buildDir}/*") as $file) {
    $zip->addFile($file, basename($file));
}

$zip->close();

echo "Created: {$zipPath}\n";
echo "Folder:  {$buildDir}\n";
echo 'Files:   questions.json + q1.jpg … q'.count($questions).".jpg\n";

function renderDiagram(string $path, string $type, array $params, int $number, string $topic): bool
{
    if (! function_exists('imagecreatetruecolor')) {
        return false;
    }

    $canvas = newDiagramCanvas();
    if ($canvas === null) {
        return false;
    }

    ['img' => $img, 'colors' => $c] = $canvas;

    drawText($img, 12, 14, "Q{$number}", $c['black'], 5);
    drawText($img, 12, 34, truncateText($topic, 42), $c['gray'], 3);

    match ($type) {
        'intersect_opposite' => drawIntersectOpposite($img, $c, $params),
        'linear_por' => drawLinearPor($img, $c, $params),
        'linear_pair' => drawLinearPair($img, $c, $params),
        'right_angle' => drawRightAngleIntersect($img, $c),
        'intersect_adjacent' => drawIntersectAdjacent($img, $c, $params),
        'perpendicular' => drawPerpendicular($img, $c),
        'intersect_adjacent_x' => drawIntersectAdjacentX($img, $c, $params),
        'parallel' => drawParallelTransversal($img, $c, $params),
        'parallel_named' => drawParallelNamed($img, $c, $params),
        default => drawIntersectOpposite($img, $c, ['angle' => 45, 'label' => '']),
    };

    $saved = imagejpeg($img, $path, 92);
    imagedestroy($img);

    return (bool) $saved;
}

function newDiagramCanvas(): ?array
{
    $width = 520;
    $height = 340;
    $img = imagecreatetruecolor($width, $height);
    if ($img === false) {
        return null;
    }

    $white = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $white);

    return [
        'img' => $img,
        'width' => $width,
        'height' => $height,
        'colors' => [
            'black' => imagecolorallocate($img, 20, 20, 20),
            'blue' => imagecolorallocate($img, 37, 99, 235),
            'red' => imagecolorallocate($img, 220, 38, 38),
            'green' => imagecolorallocate($img, 22, 163, 74),
            'gray' => imagecolorallocate($img, 100, 100, 100),
            'light' => imagecolorallocate($img, 219, 234, 254),
        ],
    ];
}

function drawText($img, int $x, int $y, string $text, int $color, int $font = 3): void
{
    imagestring($img, $font, $x, $y, $text, $color);
}

function truncateText(string $text, int $max): string
{
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
}

function drawThickLine($img, int $x1, int $y1, int $x2, int $y2, int $color, int $thickness = 2): void
{
    imagesetthickness($img, $thickness);
    imageline($img, $x1, $y1, $x2, $y2, $color);
    imagesetthickness($img, 1);
}

function drawAngleArc($img, int $cx, int $cy, int $r, float $startDeg, float $endDeg, int $color): void
{
    imagearc($img, $cx, $cy, $r * 2, $r * 2, (int) $startDeg, (int) $endDeg, $color);
}

function drawIntersectOpposite($img, array $c, array $params): void
{
    $ox = 260;
    $oy = 190;
    $angle = (float) ($params['angle'] ?? 45);

    drawThickLine($img, 90, 110, 430, 270, $c['blue']);
    drawThickLine($img, 90, 270, 430, 110, $c['blue']);

    drawText($img, 78, 98, 'A', $c['black'], 4);
    drawText($img, 432, 272, 'B', $c['black'], 4);
    drawText($img, 78, 272, 'C', $c['black'], 4);
    drawText($img, 432, 98, 'D', $c['black'], 4);
    drawText($img, $ox - 8, $oy + 10, 'O', $c['black'], 4);

    $arcStart = 360 - $angle;
    drawAngleArc($img, $ox, $oy, 34, $arcStart, 360, $c['red']);

    $label = trim((string) ($params['label'] ?? ''));
    $valueLabel = $label !== '' ? "{$label} = {$angle} deg" : "{$angle} deg";
    drawText($img, $ox + 18, $oy - 28, $valueLabel, $c['red'], 3);
}

function drawLinearPor($img, array $c, array $params): void
{
    $angle = (float) ($params['angle'] ?? 47);
    $oy = 230;
    $ox = 260;

    drawThickLine($img, 70, $oy, 450, $oy, $c['blue']);
    $rx = (int) ($ox + 120 * cos(deg2rad(180 - $angle)));
    $ry = (int) ($oy - 120 * sin(deg2rad(180 - $angle)));
    drawThickLine($img, $ox, $oy, $rx, $ry, $c['blue']);

    drawText($img, 58, $oy + 8, 'P', $c['black'], 4);
    drawText($img, $ox - 6, $oy + 12, 'O', $c['black'], 4);
    drawText($img, 452, $oy + 8, 'Q', $c['black'], 4);
    drawText($img, $rx + 4, $ry - 18, 'R', $c['black'], 4);

    drawAngleArc($img, $ox, $oy, 38, 180 - $angle, 180, $c['red']);
    drawText($img, $ox - 58, $oy - 34, "POR = {$angle} deg", $c['red'], 3);
}

function drawLinearPair($img, array $c, array $params): void
{
    $angle = (float) ($params['angle'] ?? 112);
    $label = (string) ($params['label'] ?? 'A');
    $oy = 240;
    $ox = 260;

    drawThickLine($img, 70, $oy, 450, $oy, $c['blue']);
    drawThickLine($img, $ox, $oy, $ox, 90, $c['blue']);

    drawAngleArc($img, $ox, $oy, 40, 270, 360, $c['red']);
    drawText($img, $ox + 14, $oy - 46, "{$label} = {$angle} deg", $c['red'], 3);
    drawText($img, $ox + 52, $oy - 8, 'B', $c['black'], 4);
    drawText($img, $ox - 10, $oy + 12, 'O', $c['black'], 4);
}

function drawRightAngleIntersect($img, array $c): void
{
    $px = 260;
    $py = 190;

    drawThickLine($img, 120, 120, 400, 260, $c['blue']);
    drawThickLine($img, 120, 260, 400, 120, $c['blue']);

    drawText($img, 248, $py + 14, 'P', $c['black'], 4);
    drawText($img, 108, 108, 'l', $c['black'], 4);
    drawText($img, 408, 108, 'm', $c['black'], 4);

    imagerectangle($img, $px + 8, $py - 28, $px + 28, $py - 8, $c['red']);
    drawText($img, $px + 34, $py - 34, '1 = 90 deg', $c['red'], 3);
}

function drawIntersectAdjacent($img, array $c, array $params): void
{
    $angle = (float) ($params['angle'] ?? 36);
    $label = (string) ($params['label'] ?? 'AOC');
    $ox = 260;
    $oy = 190;

    drawThickLine($img, 90, 110, 430, 270, $c['blue']);
    drawThickLine($img, 90, 270, 430, 110, $c['blue']);

    drawText($img, 78, 98, 'A', $c['black'], 4);
    drawText($img, 432, 272, 'B', $c['black'], 4);
    drawText($img, 78, 272, 'C', $c['black'], 4);
    drawText($img, 432, 98, 'D', $c['black'], 4);
    drawText($img, $ox - 8, $oy + 12, 'O', $c['black'], 4);

    drawAngleArc($img, $ox, $oy, 34, 360 - $angle, 360, $c['red']);
    drawText($img, $ox + 16, $oy - 30, "{$label} = {$angle} deg", $c['red'], 3);
    drawText($img, $ox - 72, $oy - 18, 'AOD = ?', $c['green'], 3);
}

function drawPerpendicular($img, array $c): void
{
    $ox = 260;
    $oy = 210;

    drawThickLine($img, 100, $oy, 420, $oy, $c['blue']);
    drawThickLine($img, $ox, $oy, $ox, 80, $c['blue']);

    drawText($img, 92, $oy + 8, 'P', $c['black'], 4);
    drawText($img, $ox - 8, $oy + 12, 'O', $c['black'], 4);
    drawText($img, $ox + 6, 68, 'Q', $c['black'], 4);

    imagerectangle($img, $ox + 8, $oy - 28, $ox + 28, $oy - 8, $c['red']);
    drawText($img, $ox + 34, $oy - 34, 'POQ = 90 deg', $c['red'], 3);
}

function drawIntersectAdjacentX($img, array $c, array $params): void
{
    $angle = (float) ($params['angle'] ?? 71);
    $ox = 260;
    $oy = 190;

    drawThickLine($img, 90, 190, 430, 190, $c['blue']);
    drawThickLine($img, $ox, 90, $ox, 290, $c['blue']);

    drawAngleArc($img, $ox, $oy, 36, 270, 360 - (180 - $angle), $c['red']);
    drawText($img, $ox + 18, $oy - 42, "x = {$angle} deg", $c['red'], 3);
    drawText($img, $ox - 88, $oy - 18, 'adjacent = ?', $c['green'], 3);
}

function drawParallelTransversal($img, array $c, array $params): void
{
    $highlight = $params['highlight'] ?? [1, 5];
    $values = $params['values'] ?? [];

    $yTop = 120;
    $yBottom = 240;
    $xLeft = 70;
    $xRight = 450;

    drawThickLine($img, $xLeft, $yTop, $xRight, $yTop, $c['blue']);
    drawThickLine($img, $xLeft, $yBottom, $xRight, $yBottom, $c['blue']);
    drawThickLine($img, 150, 60, 390, 300, $c['blue']);

    drawText($img, 52, $yTop - 8, 'l', $c['black'], 4);
    drawText($img, 52, $yBottom - 8, 'm', $c['black'], 4);
    drawText($img, 396, 52, 't', $c['black'], 4);

    $topX = 285;
    $bottomX = 255;

    $positions = [
        1 => [$topX - 34, $yTop - 28],
        2 => [$topX + 10, $yTop - 28],
        3 => [$topX + 10, $yTop + 8],
        4 => [$topX - 34, $yTop + 8],
        5 => [$bottomX - 34, $yBottom - 28],
        6 => [$bottomX + 10, $yBottom - 28],
        7 => [$bottomX + 10, $yBottom + 8],
        8 => [$bottomX - 34, $yBottom + 8],
    ];

    foreach ($positions as $num => [$x, $y]) {
        $isHighlight = in_array($num, $highlight, true);
        $color = $isHighlight ? $c['red'] : $c['gray'];
        $value = $values[$num] ?? null;
        $label = $value !== null ? "angle {$num} = {$value}" : "angle {$num}";
        drawText($img, $x, $y, $label, $color, $isHighlight ? 3 : 2);
    }
}

function drawParallelNamed($img, array $c, array $params): void
{
    $angle = (float) ($params['angle'] ?? 73);
    $yTop = 120;
    $yBottom = 240;

    drawThickLine($img, 70, $yTop, 450, $yTop, $c['blue']);
    drawThickLine($img, 70, $yBottom, 450, $yBottom, $c['blue']);
    drawThickLine($img, 160, 60, 380, 300, $c['blue']);

    drawText($img, 52, $yTop - 8, 'A', $c['black'], 4);
    drawText($img, 452, $yTop - 8, 'B', $c['black'], 4);
    drawText($img, 52, $yBottom - 8, 'C', $c['black'], 4);
    drawText($img, 452, $yBottom - 8, 'D', $c['black'], 4);
    drawText($img, 384, 52, 'E', $c['black'], 4);
    drawText($img, 156, 292, 'F', $c['black'], 4);

    drawText($img, 300, 148, "AEF = {$angle} deg", $c['red'], 3);
    drawText($img, 250, 228, "EFD = ?", $c['green'], 3);
}

function minimalJpegBytes(): string
{
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//AP//2wBDAQoLCw4NDx0QEB0VICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgL/wAARCAABAAEDAREAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA8A/9k=',
        true,
    ) ?: '';
}
