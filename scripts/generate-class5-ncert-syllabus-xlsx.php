<?php

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** @var array<int, string> NCERT chapter number → Mentor Maths chapter head */
$mainTopicByChapter = [
    1 => 'Number System',
    2 => 'Ratio & Proportion',
    3 => 'Geometry',
    4 => 'Speed Distance Time',
    5 => 'Perimeter and Area',
    6 => 'Number System',
    7 => 'Geometry',
    8 => 'Perimeter and Area',
    9 => 'Ratio & Proportion',
    10 => 'Geometry',
    11 => 'Geometry',
    12 => 'Speed Distance Time',
    13 => 'Perimeter and Area',
    14 => 'Geometry',
    15 => 'Statistics',
];

/** @var array<int, string> Exact NCERT chapter titles (Joyful Mathematics, Class 5) */
$ncertTitleByChapter = [
    1 => 'We the Travellers—I',
    2 => 'Fractions',
    3 => 'Angles as Turns',
    4 => 'We the Travellers—II',
    5 => 'Far and Near',
    6 => 'The Dairy Farm',
    7 => 'Shapes and Patterns',
    8 => 'Weight and Capacity',
    9 => 'Coconut Farm',
    10 => 'Symmetrical Designs',
    11 => 'Grandmother\'s Quilt',
    12 => 'Racing Seconds',
    13 => 'Animal Jumps',
    14 => 'Maps and Locations',
    15 => 'Data Through Pictures',
];

/**
 * chapter_no, sub_topic, learning_outcomes, periods, remarks (optional note)
 *
 * @var list<array{0:int,1:string,2:string,3:int,4?:string}>
 */
$sourceRows = [
    // 1 — We the Travellers—I
    [1, 'Large Numbers in Travel', 'Reading and writing numbers up to lakhs in journey contexts', 4, 'NCERT opening chapter'],
    [1, 'Routes and Distances', 'Interpreting route charts, comparing distances between places', 3, ''],
    [1, 'Planning a Journey', 'Estimating cost, time and resources for a trip', 3, ''],

    // 2 — Fractions
    [2, 'Unit and Like Fractions', 'Understanding numerator, denominator and equal parts of a whole', 4, ''],
    [2, 'Equivalent Fractions', 'Finding equal fractions using models and multiplication', 4, ''],
    [2, 'Comparing Fractions', 'Ordering fractions with same denominator or using benchmarks', 3, ''],

    // 3 — Angles as Turns
    [3, 'Turns as Fractions of a Circle', 'Full, half, quarter and three-quarter turns', 3, ''],
    [3, 'Measuring Turns', 'Relating turns to right angles and everyday rotation', 3, ''],
    [3, 'Angles in Movement', 'Describing direction change using turn language', 2, ''],

    // 4 — We the Travellers—II
    [4, 'Reading Timetables', 'Using bus and train schedules to plan travel', 4, ''],
    [4, 'Distance and Time Estimates', 'Linking speed, distance and time in simple situations', 4, ''],
    [4, 'Multi-step Travel Problems', 'Addition and subtraction in route-planning contexts', 3, ''],

    // 5 — Far and Near
    [5, 'Comparing Distances', 'Near/far, longer/shorter using non-standard units', 3, ''],
    [5, 'Standard Units of Length', 'Using cm, m and km with rulers and measuring tapes', 4, ''],
    [5, 'Length Word Problems', 'Addition and subtraction problems involving distance', 3, ''],

    // 6 — The Dairy Farm
    [6, 'Multiplication in Farming', 'Equal groups, arrays and repeated addition for milk yield', 4, ''],
    [6, 'Division and Sharing', 'Sharing produce equally among workers or containers', 4, ''],
    [6, 'Farm Budget Problems', 'Simple cost and quantity problems in a dairy setting', 3, ''],

    // 7 — Shapes and Patterns
    [7, '2D Shapes and Properties', 'Identifying sides, corners and names of common shapes', 3, ''],
    [7, 'Tessellations and Tiling', 'Repeating shapes to cover a surface without gaps', 3, ''],
    [7, 'Creating Shape Patterns', 'Designing borders, rangoli and repeating motifs', 2, ''],

    // 8 — Weight and Capacity
    [8, 'Kilograms and Grams', 'Weighing objects and converting between kg and g', 4, ''],
    [8, 'Litres and Millilitres', 'Measuring liquids and reading capacity scales', 4, ''],
    [8, 'Weight and Capacity Problems', 'Applied problems on shopping and cooking quantities', 3, ''],

    // 9 — Coconut Farm
    [9, 'Fractions of a Harvest', 'Finding half, third or quarter of a collection of coconuts', 4, ''],
    [9, 'Equal Sharing on the Farm', 'Division and grouping in harvesting and packing contexts', 3, ''],
    [9, 'Farm Income Problems', 'Simple profit and sharing problems using fractions', 3, ''],

    // 10 — Symmetrical Designs
    [10, 'Line Symmetry', 'Identifying lines of symmetry in shapes and letters', 3, ''],
    [10, 'Completing Symmetric Figures', 'Drawing mirror halves of patterns and objects', 3, ''],
    [10, 'Symmetry in Art', 'Creating symmetric rangoli, kolam and craft designs', 2, ''],

    // 11 — Grandmother's Quilt
    [11, 'Patchwork Patterns', 'Arranging coloured squares and triangles on a grid', 3, ''],
    [11, 'Fractions on a Quilt', 'Shading fractional parts of a patchwork layout', 3, ''],
    [11, 'Designing a Quilt Plan', 'Planning rows, columns and repeating colour blocks', 2, ''],

    // 12 — Racing Seconds
    [12, 'Reading Time to the Second', 'Using clocks and stopwatches with seconds', 3, ''],
    [12, 'Comparing Race Times', 'Finding faster/slower by comparing mm:ss readings', 4, ''],
    [12, 'Elapsed Time Problems', 'Calculating time taken in short-duration events', 3, ''],

    // 13 — Animal Jumps
    [13, 'Measuring Jump Lengths', 'Recording how far animals jump using standard units', 3, ''],
    [13, 'Comparing and Ordering Lengths', 'Ranking jump data from shortest to longest', 3, ''],
    [13, 'Average Jump (Intro)', 'Finding a typical jump length from a small data set', 3, ''],

    // 14 — Maps and Locations
    [14, 'Grid References', 'Locating places using row–column grids on maps', 4, ''],
    [14, 'Cardinal Directions', 'North, south, east, west and relative position', 3, ''],
    [14, 'Scale and Distance on Maps', 'Simple scale reading (e.g. 1 cm = 1 km)', 4, ''],

    // 15 — Data Through Pictures
    [15, 'Collecting and Tallying Data', 'Recording observations using tally marks', 3, ''],
    [15, 'Pictographs', 'Representing data with symbols and a scale', 4, ''],
    [15, 'Reading Bar Graphs', 'Answering questions from bar charts and pictures', 3, ''],
];

$headers = [
    'Chapter No.',
    'Main Topic (Chapter)',
    'chapter ',
    'Sub-Topic',
    'Key Concepts / Learning Outcomes',
    'Approx. Periods',
];

    $targets = [
    __DIR__.'/../tests/CBSE_Class5_Maths_Syllabus_2026-27.xlsx',
    __DIR__.'/../tests/Class5_Math r1.xlsx',
    __DIR__.'/../tests/FINAL 5.xlsx',
    __DIR__.'/../samples/syllabus-import/CBSE_Class5_Maths_Syllabus_2026-27.xlsx',
    __DIR__.'/../samples/syllabus-import/CBSE_Class5_Maths_Syllabus_TEMPLATE.xlsx',
    __DIR__.'/../public/samples/syllabus-import/CBSE_Class5_Maths_Syllabus_2026-27.xlsx',
    __DIR__.'/../public/samples/syllabus-import/CBSE_Class5_Maths_Syllabus_TEMPLATE.xlsx',
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Class 5 Syllabus');

foreach ($headers as $index => $header) {
    $sheet->setCellValue([$index + 1, 1], $header);
}

$rowNumber = 2;
$totalPeriods = 0;

foreach ($sourceRows as $row) {
    $chapterNo = $row[0];
    $mentorHead = $mainTopicByChapter[$chapterNo];
    $ncertTitle = $ncertTitleByChapter[$chapterNo];

    $out = [
        (string) $chapterNo,
        $ncertTitle,
        $mentorHead,
        $row[1],
        $row[2],
        (string) $row[3],
        '',
    ];

    foreach ($out as $index => $value) {
        $sheet->setCellValue([$index + 1, $rowNumber], $value);
    }

    $totalPeriods += $row[3];
    $rowNumber++;
}

foreach (range('A', 'F') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

foreach ($targets as $path) {
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    (new Xlsx($spreadsheet))->save($path);
    fwrite(STDOUT, "Saved {$path}\n");
}

fwrite(STDOUT, 'Rows: '.count($sourceRows)." · Chapters: 15 · Total periods: {$totalPeriods}\n");
