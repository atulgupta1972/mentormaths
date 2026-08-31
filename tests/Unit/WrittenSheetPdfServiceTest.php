<?php

namespace Tests\Unit;

use App\Models\Worksheet;
use App\Services\WrittenSheetPdfService;
use App\Support\PracticeSetScope;
use Tests\TestCase;

class WrittenSheetPdfServiceTest extends TestCase
{
    public function test_written_sheet_pdf_view_includes_answer_format_guidance(): void
    {
        $worksheet = Worksheet::make([
            'set_code' => 'T743-W',
            'scope' => PracticeSetScope::CHAPTER,
        ]);

        $html = view('reports.written-sheet-pdf', [
            'worksheet' => $worksheet,
            'questions' => [
                ['number' => 1, 'text' => 'Sample question', 'diagram_path' => null, 'type' => 'fill_in_blank', 'options' => []],
            ],
            'className' => 'Class 7',
            'boardCode' => 'CBSE',
            'chapterName' => 'Algebraic Expressions',
            'topicName' => null,
            'kindLabel' => 'Test',
        ])->render();

        $this->assertStringContainsString('How to write each answer (on your answer sheet)', $html);
        $this->assertStringContainsString('1. Given:', $html);
        $this->assertStringContainsString('2. To find:', $html);
        $this->assertStringContainsString('3. Solution:', $html);
        $this->assertStringContainsString('4. Answer:', $html);
        $this->assertStringContainsString('not from this sheet', $html);
        $this->assertStringNotContainsString('page-break-before: always', $html);
    }

    public function test_question_text_for_sheet_expands_blanks_for_print(): void
    {
        $service = app(WrittenSheetPdfService::class);

        $this->assertSame(
            '(-12) + 8 = ______',
            $service->questionTextForSheet('(-12) + 8 = ____'),
        );

        $this->assertSame(
            'Find 3/4 of ______',
            $service->questionTextForSheet('Find 3/4 of ____'),
        );
    }

    public function test_question_text_for_sheet_keeps_inline_blanks(): void
    {
        $service = app(WrittenSheetPdfService::class);

        $this->assertSame(
            'Simplify: 3(2x + 1) - (x - 4) = ______ x + 7.',
            $service->questionTextForSheet('Simplify: 3(2x + 1) - (x - 4) = ___ x + 7.'),
        );

        $this->assertSame(
            'When (5x + 3) + (2x - 7) is simplified, the coefficient of x is ______',
            $service->questionTextForSheet('When (5x + 3) + (2x - 7) is simplified, the coefficient of x is ____'),
        );
    }
}
