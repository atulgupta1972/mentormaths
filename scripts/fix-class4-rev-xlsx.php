<?php

require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$source = __DIR__.'/../tests/Class4_Maths_Revised_Syllabus_2026-27 rev.xlsx';
$target = __DIR__.'/../tests/Class4_Maths_Revised_Syllabus_2026-27 rev.xlsx';
$copyTarget = __DIR__.'/../samples/syllabus-import/CBSE_Class4_Maths_Syllabus_2026-27.xlsx';

$rows = IOFactory::load($source)->getActiveSheet()->toArray(null, true, true, false);
$headers = [
    'Chapter No.',
    'Main Topic (Chapter)',
    'Sub-Topic',
    'Key Concepts / Learning Outcomes',
    'Approx. Periods',
    'Remarks',
];

$outRows = [];
for ($i = 1; $i < count($rows); $i++) {
    $row = $rows[$i];
    if ($row[0] === null && $row[1] === null) {
        continue;
    }

    $chapterNo = trim((string) ($row[0] ?? ''));
    $mainTopic = trim((string) ($row[1] ?? ''));
    $ncertChapter = trim((string) ($row[2] ?? ''));
    $subTopic = trim((string) ($row[3] ?? ''));
    $concepts = trim((string) ($row[4] ?? ''));
    $periods = trim((string) ($row[5] ?? ''));

    if ($chapterNo === '' && $mainTopic === '' && $subTopic === '') {
        continue;
    }

    $remarks = $ncertChapter !== '' ? "NCERT: {$ncertChapter}" : '';

    $outRows[] = [$chapterNo, $mainTopic, $subTopic, $concepts, $periods, $remarks];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Class 4 Syllabus');

foreach ($headers as $index => $header) {
    $sheet->setCellValue([$index + 1, 1], $header);
}

$rowNumber = 2;
foreach ($outRows as $row) {
    foreach ($row as $index => $value) {
        $sheet->setCellValue([$index + 1, $rowNumber], $value);
    }
    $rowNumber++;
}

foreach (range('A', 'F') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

(new Xlsx($spreadsheet))->save($target);
copy($target, $copyTarget);

fwrite(STDOUT, 'Fixed '.count($outRows)." rows in {$target}\n");
