<?php

namespace Tests\Unit;

use App\Services\TextbookChapterMcqImportService;
use Tests\TestCase;

class TextbookChapterMcqImportServiceTest extends TestCase
{
    public function test_import_merges_table_data_into_question_text(): void
    {
        $json = json_encode([
            'questions' => [
                [
                    'topic' => 'Data handling',
                    'question' => 'Who read the most books?',
                    'table' => [
                        'headers' => ['Student', 'Books read'],
                        'rows' => [
                            ['Anya', '5'],
                            ['Bhuvan', '8'],
                        ],
                    ],
                    'options' => ['Anya', 'Bhuvan', 'Chitra', 'Dev'],
                    'correct_index' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $items = app(TextbookChapterMcqImportService::class)->parseToItems($json);

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Who read the most books?', $items[0]['question_text']);
        $this->assertStringContainsString("Table:\nStudent | Books read", $items[0]['question_text']);
        $this->assertStringContainsString('Bhuvan | 8', $items[0]['question_text']);
    }

    public function test_import_merges_chart_description_into_question_text(): void
    {
        $json = json_encode([
            'questions' => [
                [
                    'question' => 'Which month had the highest sales?',
                    'chart' => "Bar chart 'Monthly sales (₹)' — Jan: 120, Feb: 180, Mar: 150",
                    'options' => ['Jan', 'Feb', 'Mar', 'Apr'],
                    'correct_index' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $items = app(TextbookChapterMcqImportService::class)->parseToItems($json);

        $this->assertStringContainsString('Which month had the highest sales?', $items[0]['question_text']);
        $this->assertStringContainsString("Chart:\nBar chart 'Monthly sales", $items[0]['question_text']);
    }

    public function test_prompt_includes_chart_and_table_extraction_rules(): void
    {
        $grade = new \App\Models\GradeLevel(['name' => 'Class 7']);
        $textbook = new \App\Models\Textbook(['name' => 'Ganita Prakash', 'code' => 'GP']);
        $textbook->setRelation('gradeLevel', $grade);
        $chapter = new \App\Models\TextbookChapter([
            'chapter_number' => 3,
            'title' => 'Data Handling',
        ]);
        $chapter->setRelation('textbook', $textbook);

        $payload = app(\App\Services\TextbookChapterMcqPromptService::class)->payload($chapter);

        $this->assertStringContainsString('Charts and tables', $payload['prompt']);
        $this->assertStringContainsString('diagram_file', $payload['prompt']);
        $this->assertStringContainsString('Books read', $payload['sample_json']);
    }
}
