<?php

use App\Models\Question;
use App\Models\TextbookChapter;
use App\Support\PublisherNameStripper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $stripper = new PublisherNameStripper;

        Question::query()->orderBy('id')->select(['id', 'question_text', 'explanation', 'method_hint'])->chunkById(200, function ($questions) use ($stripper) {
            foreach ($questions as $question) {
                $updates = [];
                foreach (['question_text', 'explanation', 'method_hint'] as $field) {
                    $text = (string) ($question->{$field} ?? '');
                    if ($text === '' || ! $stripper->changed($text)) {
                        continue;
                    }
                    $updates[$field] = $stripper->strip($text);
                }
                if ($updates !== []) {
                    $question->update($updates);
                }
            }
        });

        if (DB::getSchemaBuilder()->hasTable('question_options')) {
            DB::table('question_options')->orderBy('id')->select(['id', 'option_text'])->chunkById(200, function ($rows) use ($stripper) {
                foreach ($rows as $row) {
                    $text = (string) ($row->option_text ?? '');
                    if ($text === '' || ! $stripper->changed($text)) {
                        continue;
                    }
                    DB::table('question_options')->where('id', $row->id)->update([
                        'option_text' => $stripper->strip($text),
                    ]);
                }
            });
        }

        if (DB::getSchemaBuilder()->hasTable('question_blank_answers')) {
            DB::table('question_blank_answers')->orderBy('id')->select(['id', 'correct_answer'])->chunkById(200, function ($rows) use ($stripper) {
                foreach ($rows as $row) {
                    $text = (string) ($row->correct_answer ?? '');
                    if ($text === '' || ! $stripper->changed($text)) {
                        continue;
                    }
                    DB::table('question_blank_answers')->where('id', $row->id)->update([
                        'correct_answer' => $stripper->strip($text),
                    ]);
                }
            });
        }

        TextbookChapter::query()->whereNotNull('extraction_items')->orderBy('id')->select(['id', 'extraction_items'])->chunkById(50, function ($chapters) use ($stripper) {
            foreach ($chapters as $chapter) {
                $items = $chapter->extraction_items;
                if (! is_array($items)) {
                    continue;
                }
                $changed = false;
                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    foreach (['question_text', 'fill_blank_question_text', 'explanation', 'fill_blank_correct_answer'] as $field) {
                        $text = (string) ($item[$field] ?? '');
                        if ($text === '' || ! $stripper->changed($text)) {
                            continue;
                        }
                        $items[$index][$field] = $stripper->strip($text);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $chapter->update(['extraction_items' => array_values($items)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Names are removed from stored text and are not restored.
    }
};
