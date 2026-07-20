<?php

namespace Tests\Unit;

use App\Services\WrittenSheetPdfService;
use Tests\TestCase;

class WrittenSheetPdfServiceTest extends TestCase
{
    public function test_question_text_for_sheet_strips_answer_blanks(): void
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
}
