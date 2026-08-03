<?php

namespace Tests\Unit;

use App\Services\WrittenSheetPdfService;
use Tests\TestCase;

class WrittenSheetPdfServiceTest extends TestCase
{
    public function test_question_text_for_sheet_strips_trailing_answer_blanks(): void
    {
        $service = app(WrittenSheetPdfService::class);

        $this->assertSame(
            '(-12) + 8',
            $service->questionTextForSheet('(-12) + 8 = ____'),
        );

        $this->assertSame(
            'Find 3/4 of',
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
            'When (5x + 3) + (2x - 7) is simplified, the coefficient of x is',
            $service->questionTextForSheet('When (5x + 3) + (2x - 7) is simplified, the coefficient of x is ____'),
        );
    }
}
