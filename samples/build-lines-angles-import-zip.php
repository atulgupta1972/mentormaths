<?php

/**
 * Build samples/lines-angles-ch5-import.zip for testing zip import.
 * Run: php samples/build-lines-angles-import-zip.php
 */

$root = dirname(__DIR__);
$buildDir = $root.'/samples/lines-angles-ch5-pack';
$zipPath = $root.'/samples/lines-angles-ch5-import.zip';
$fontDir = $root.'/storage/fonts';

if (! is_dir($fontDir)) {
    mkdir($fontDir, 0755, true);
}

$fontTargets = [
    'C:/Windows/Fonts/segoeui.ttf' => $fontDir.'/DejaVuSans.ttf',
    'C:/Windows/Fonts/arial.ttf' => $fontDir.'/DejaVuSans.ttf',
];

foreach ($fontTargets as $source => $dest) {
    if (! is_file($dest) && is_file($source)) {
        copy($source, $dest);
        break;
    }
}

require __DIR__.'/lib/diagram-renderer.php';

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
    ['topic' => 'Related Angles', 'question' => 'In the figure, ray OX stands on line PQ. If ∠POX = 61°, then ∠XOQ = ____°.', 'answer' => '119', 'hint' => 'Angles on a straight line sum to 180°.', 'explanation' => '∠XOQ = 180° − 61° = 119°.', 'difficulty' => 'Medium', 'diagram' => 'linear_por', 'params' => ['angle' => 61]],
    ['topic' => 'Pairs of Lines', 'question' => 'In the figure, lines AB and CD intersect at O. If ∠BOD = 127°, then ∠AOC = ____°.', 'answer' => '127', 'hint' => 'Vertically opposite angles are equal.', 'explanation' => '∠AOC and ∠BOD are vertically opposite, so ∠AOC = 127°.', 'difficulty' => 'Medium', 'diagram' => 'intersect_opposite', 'params' => ['angle' => 127, 'label' => 'BOD']],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m and t is a transversal. If ∠8 = 57°, then the corresponding angle ∠4 = ____°.', 'answer' => '57', 'hint' => 'Corresponding angles are equal for parallel lines.', 'explanation' => '∠4 = 57°.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [4, 8], 'values' => [4 => 57, 8 => 57]]],
    ['topic' => 'Parallel Lines & Transversal', 'question' => 'In the figure, l ∥ m. Co-interior angles are (2x + 20)° and (3x − 10)°. Then x = ____.', 'answer' => '34', 'hint' => 'Co-interior angles sum to 180°.', 'explanation' => '2x + 20 + 3x − 10 = 180 ⇒ 5x + 10 = 180 ⇒ x = 34.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [4, 5], 'values' => [4 => '2x+20', 5 => '3x-10']]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, alternate exterior angles are 49° and 49°. The lines are parallel. (Answer 1 for yes, 0 for no.)', 'answer' => '1', 'hint' => 'Equal alternate exterior angles imply parallel lines.', 'explanation' => 'Both angles are 49°, so the lines are parallel. Answer: 1.', 'difficulty' => 'Medium', 'diagram' => 'parallel', 'params' => ['highlight' => [1, 8], 'values' => [1 => 49, 8 => 49]]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, corresponding angles are (4x − 12)° and (2x + 18)°. For parallel lines, x = ____.', 'answer' => '15', 'hint' => 'Set corresponding angles equal.', 'explanation' => '4x − 12 = 2x + 18 ⇒ 2x = 30 ⇒ x = 15.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [1, 5], 'values' => [1 => '4x-12', 5 => '2x+18']]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, alternate interior angles are (5y − 20)° and (3y + 10)°. For parallel lines, y = ____.', 'answer' => '15', 'hint' => 'Alternate interior angles must be equal.', 'explanation' => '5y − 20 = 3y + 10 ⇒ 2y = 30 ⇒ y = 15.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [3, 6], 'values' => [3 => '5y-20', 6 => '3y+10']]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, co-interior angles are (2p + 35)° and (3p − 5)°. For parallel lines, p = ____.', 'answer' => '30', 'hint' => 'Co-interior angles sum to 180°.', 'explanation' => '2p + 35 + 3p − 5 = 180 ⇒ 5p + 30 = 180 ⇒ p = 30.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [4, 5], 'values' => [4 => '2p+35', 5 => '3p-5']]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, corresponding angles are (6m − 50)° and (4m + 10)°. For parallel lines, m = ____.', 'answer' => '30', 'hint' => 'Equal corresponding angles give a linear equation.', 'explanation' => '6m − 50 = 4m + 10 ⇒ 2m = 60 ⇒ m = 30.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [1, 5], 'values' => [1 => '6m-50', 5 => '4m+10']]],
    ['topic' => 'Checking Parallel Lines', 'question' => 'In the figure, alternate interior angles are (7n + 5)° and (5n + 29)°. For parallel lines, n = ____.', 'answer' => '12', 'hint' => 'Set alternate interior angles equal for parallel lines.', 'explanation' => '7n + 5 = 5n + 29 ⇒ 2n = 24 ⇒ n = 12.', 'difficulty' => 'Hard', 'diagram' => 'parallel', 'params' => ['highlight' => [3, 6], 'values' => [3 => '7n+5', 6 => '5n+29']]],
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
        'needs_diagram' => true,
        'diagram_file' => "q{$n}.png",
        'answer_format' => 'integer',
        'correct_answer' => $q['answer'],
        'method_hint' => $q['hint'],
        'explanation' => $q['explanation'],
        'difficulty' => $q['difficulty'],
    ];

    $imagePath = "{$buildDir}/q{$n}.png";
    if (! renderDiagram($imagePath, $q['diagram'], $q['params'] ?? [], $n, $q['topic'])) {
        file_put_contents($imagePath, minimalPngBytes());
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
echo 'Files:   questions.json + q1.png … q'.count($questions).".png\n";
