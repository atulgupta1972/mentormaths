<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'ratios-equation')->value('id');
        if (! $cardId) {
            return;
        }

        $existing = DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->get(['id', 'answers']);
        foreach ($existing as $row) {
            $answers = json_decode((string) $row->answers, true);
            if (($answers['mode'] ?? '') === 'ratio_quantities') {
                return;
            }
        }

        $now = now();
        $items = [];
        $check = [];
        foreach ($this->sums() as $index => $sum) {
            $key = 's'.($index + 1);
            $quantities = [];
            foreach ($sum['quantities'] as $qty) {
                $quantities[] = ['key' => $qty['key'], 'name' => $qty['name']];
                $check[$key.'.'.$qty['key']] = [
                    'value' => $qty['value'],
                    'unit' => $sum['unit'],
                    'traps' => $qty['traps'],
                ];
            }
            $items[] = [
                'key' => $key,
                'story' => $sum['story'],
                'unit' => $sum['unit'],
                'columns' => ['Quantity', 'Ratio part', 'Amount in x', 'Value'],
                'quantities' => $quantities,
                'hint' => $sum['hint'],
            ];
        }

        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 6,
            'difficulty' => 'easy',
            'is_demo' => false,
            'question_text' => 'Ten ratio sums. Make the units the same, build the small table, and write each quantity.',
            'answers' => json_encode([
                'mode' => 'ratio_quantities',
                'play' => [
                    'page_blurb' => 'The headings show the small table to draw in your copy. Where the two measures use different units, change both to the unit named under the sum. x is only the common factor. Write each quantity, which is its ratio part times x.',
                    'items' => $items,
                ],
                'check' => ['items' => $check],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sums(): array
    {
        $part = fn (int $n) => ['n' => $n, 'why' => 'part'];
        $factor = fn (int $n) => ['n' => $n, 'why' => 'factor'];
        $unit = fn (int $n) => ['n' => $n, 'why' => 'unit'];

        return [
            [
                'story' => 'The number of boys to the number of girls is 3 : 5. Together there are 40 students. Write both quantities as numbers of students.',
                'unit' => 'students',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Boys', 'value' => 15, 'traps' => [$part(3), $factor(5)]],
                    ['key' => 'second', 'name' => 'Girls', 'value' => 25, 'traps' => [$part(5), $factor(5)]],
                ],
                'hint' => 'Boys 3x, girls 5x. 3x + 5x = 40, so x = 5. Boys 15, girls 25.',
            ],
            [
                'story' => 'Rice to dal is 2 kg : 500 g. Together they weigh 5 kg. Write both quantities in grams.',
                'unit' => 'grams',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Rice', 'value' => 4000, 'traps' => [$unit(2), $part(4), $factor(1000)]],
                    ['key' => 'second', 'name' => 'Dal', 'value' => 1000, 'traps' => [$unit(500), $part(1)]],
                ],
                'hint' => '2 kg = 2000 g, so the ratio is 2000 : 500 = 4 : 1. Together 5000 g. 4x + x = 5000, x = 1000. Rice 4000 g, dal 1000 g.',
            ],
            [
                'story' => 'Ribbon to lace is 3 m : 75 cm. Together they are 4.5 m. Write both quantities in centimetres.',
                'unit' => 'cm',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Ribbon', 'value' => 360, 'traps' => [$unit(3), $part(4), $factor(90)]],
                    ['key' => 'second', 'name' => 'Lace', 'value' => 90, 'traps' => [$unit(75), $part(1)]],
                ],
                'hint' => '3 m = 300 cm, so the ratio is 300 : 75 = 4 : 1. Together 450 cm. 4x + x = 450, x = 90. Ribbon 360 cm, lace 90 cm.',
            ],
            [
                'story' => 'Study time to play time is 2 hours : 40 minutes. Together the time is 4 hours. Write both quantities in minutes.',
                'unit' => 'minutes',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Study', 'value' => 180, 'traps' => [$unit(2), $part(3), $factor(60)]],
                    ['key' => 'second', 'name' => 'Play', 'value' => 60, 'traps' => [$unit(40), $part(1)]],
                ],
                'hint' => '2 hours = 120 minutes, so the ratio is 120 : 40 = 3 : 1. Together 240 minutes. 3x + x = 240, x = 60. Study 180 minutes, play 60 minutes.',
            ],
            [
                'story' => 'The cost of a book to the cost of a pen is ₹2 : 80 paise. Together they cost ₹5.60. Write both quantities in paise.',
                'unit' => 'paise',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Book', 'value' => 400, 'traps' => [$unit(2), $part(5), $factor(80)]],
                    ['key' => 'second', 'name' => 'Pen', 'value' => 160, 'traps' => [$unit(80), $part(2)]],
                ],
                'hint' => '₹2 = 200 paise, so the ratio is 200 : 80 = 5 : 2. Together 560 paise. 5x + 2x = 560, x = 80. Book 400 paise, pen 160 paise.',
            ],
            [
                'story' => 'Red cloth to blue cloth is 2 m : 80 cm. Together the cloth is 7 m. Write both quantities in centimetres.',
                'unit' => 'cm',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Red cloth', 'value' => 500, 'traps' => [$unit(2), $part(5), $factor(100)]],
                    ['key' => 'second', 'name' => 'Blue cloth', 'value' => 200, 'traps' => [$unit(80), $part(2), $factor(100)]],
                ],
                'hint' => '2 m = 200 cm, so the ratio is 200 : 80 = 5 : 2. Together 700 cm. 5x + 2x = 700, x = 100. Red cloth 500 cm, blue cloth 200 cm.',
            ],
            [
                'story' => 'Homework time to games time is 45 minutes : 1 hour. Together the time is 3 hours 30 minutes. Write both quantities in minutes.',
                'unit' => 'minutes',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Homework', 'value' => 90, 'traps' => [$unit(45), $part(3), $factor(30)]],
                    ['key' => 'second', 'name' => 'Games', 'value' => 120, 'traps' => [$unit(1), $part(4), $factor(30)]],
                ],
                'hint' => '1 hour = 60 minutes, so the ratio is 45 : 60 = 3 : 4. Together 210 minutes. 3x + 4x = 210, x = 30. Homework 90 minutes, games 120 minutes.',
            ],
            [
                'story' => 'Juice to water is 750 ml : 1 litre. Together the drink is 3.5 litres. Write both quantities in millilitres.',
                'unit' => 'ml',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Juice', 'value' => 1500, 'traps' => [$unit(750), $part(3), $factor(500)]],
                    ['key' => 'second', 'name' => 'Water', 'value' => 2000, 'traps' => [$unit(1), $part(4), $factor(500)]],
                ],
                'hint' => '1 litre = 1000 ml, so the ratio is 750 : 1000 = 3 : 4. Together 3500 ml. 3x + 4x = 3500, x = 500. Juice 1500 ml, water 2000 ml.',
            ],
            [
                'story' => 'Sand to cement is 3 kg : 750 g. Together they weigh 7.5 kg. Write both quantities in grams.',
                'unit' => 'grams',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Sand', 'value' => 6000, 'traps' => [$unit(3), $part(4), $factor(1500)]],
                    ['key' => 'second', 'name' => 'Cement', 'value' => 1500, 'traps' => [$unit(750), $part(1)]],
                ],
                'hint' => '3 kg = 3000 g, so the ratio is 3000 : 750 = 4 : 1. Together 7500 g. 4x + x = 7500, x = 1500. Sand 6000 g, cement 1500 g.',
            ],
            [
                'story' => 'Milk to water is 2 litres : 800 ml. Together the mixture is 5.6 litres. Write both quantities in millilitres.',
                'unit' => 'ml',
                'quantities' => [
                    ['key' => 'first', 'name' => 'Milk', 'value' => 4000, 'traps' => [$unit(2), $part(5), $factor(800)]],
                    ['key' => 'second', 'name' => 'Water', 'value' => 1600, 'traps' => [$unit(800), $part(2)]],
                ],
                'hint' => '2 litres = 2000 ml, so the ratio is 2000 : 800 = 5 : 2. Together 5600 ml. 5x + 2x = 5600, x = 800. Milk 4000 ml, water 1600 ml.',
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'ratios-equation')->value('id');
        if (! $cardId) {
            return;
        }

        $rows = DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->get(['id', 'answers']);
        foreach ($rows as $row) {
            $answers = json_decode((string) $row->answers, true);
            if (($answers['mode'] ?? '') === 'ratio_quantities') {
                DB::table('mmm_card_exercises')->where('id', $row->id)->delete();
            }
        }
    }
};
