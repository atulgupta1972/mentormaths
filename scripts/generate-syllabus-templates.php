<?php

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$dir = __DIR__.'/../samples/syllabus-import';
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$headers = [
    'Chapter No.',
    'Main Topic (Chapter)',
    'Sub-Topic',
    'Key Concepts / Learning Outcomes',
    'Difficulty Level',
    'Approx. Periods',
    'Remarks',
];

$templates = [
    'Class4' => [
        ['1', 'Building with Bricks', 'Patterns with bricks', 'Identify patterns in brick arrangements', 'Easy', '8', 'NCERT Ch 1 — replace with official CBSE syllabus'],
        ['1', 'Building with Bricks', 'Cost of wall / floor', 'Simple multiplication using brick count', 'Medium', '8', ''],
        ['2', 'Long and Short', 'Measuring length', 'Compare lengths using hand span, foot, cubit', 'Easy', '10', 'NCERT Ch 2'],
        ['2', 'Long and Short', 'Metre and centimetre', 'Use cm and m for everyday objects', 'Easy', '10', ''],
        ['3', 'A Trip to Bhopal', 'Addition and subtraction in context', 'Solve word problems from travel scenarios', 'Medium', '12', 'NCERT Ch 3 — sample only'],
    ],
    'Class5' => [
        ['1', 'The Fish Tale', 'Large numbers in real life', 'Read and write numbers beyond 10000', 'Easy', '8', 'NCERT Ch 1 — replace with official CBSE syllabus'],
        ['2', 'Shapes and Angles', 'Types of angles', 'Identify acute, right, obtuse angles', 'Easy', '10', 'NCERT Ch 2'],
        ['2', 'Shapes and Angles', 'Measuring angles', 'Use protractor; estimate angle size', 'Medium', '10', ''],
        ['3', 'How Many Squares?', 'Area by counting squares', 'Find area on grid paper', 'Easy', '12', 'NCERT Ch 3 — sample only'],
    ],
];

foreach ($templates as $label => $rows) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Syllabus');

    foreach ($headers as $index => $header) {
        $sheet->setCellValue([$index + 1, 1], $header);
    }

    $rowNumber = 2;
    foreach ($rows as $row) {
        foreach ($row as $index => $value) {
            $sheet->setCellValue([$index + 1, $rowNumber], $value);
        }
        $rowNumber++;
    }

    foreach (range('A', 'G') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $path = $dir.'/CBSE_'.$label.'_Maths_Syllabus_TEMPLATE.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    fwrite(STDOUT, "Created {$path}\n");
}
