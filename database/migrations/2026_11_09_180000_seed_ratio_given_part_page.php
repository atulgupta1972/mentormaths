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
            if (($answers['play']['kind'] ?? '') === 'given_part') {
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
                'cue' => $sum['cue'],
                'columns' => ['Quantity', 'Ratio part', 'Amount in x', 'Value'],
                'quantities' => $quantities,
                'hint' => $sum['hint'],
            ];
        }

        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 7,
            'difficulty' => 'hard',
            'is_demo' => false,
            'question_text' => 'Ten ratio sums. One part is given. Find the common factor from that part, then write each quantity.',
            'answers' => json_encode([
                'mode' => 'ratio_quantities',
                'play' => [
                    'kind' => 'given_part',
                    'page_blurb' => 'One amount in the ratio is given. Write both amounts as ratio parts times x. Set the given part equal to the given amount and find x. x is only the factor. Each quantity is its ratio part times x. Where the units differ, change both to the unit named under the sum before you start.',
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
        $given = fn (int $n) => ['n' => $n, 'why' => 'given'];
        $scale = fn (int $n) => ['n' => $n, 'why' => 'scale'];
        $swap = fn (int $n) => ['n' => $n, 'why' => 'swap'];
        $same = 'One amount is given. In the table, that amount is its ratio part times x. Find x from that line, then fill the other quantity.';
        $mixed = 'Change both measures to the named unit, including the given amount. Then set that ratio part times x equal to the given amount and find x.';

        return [
            [
                'story' => 'Boys to girls is 3 : 5. There are 24 boys. Write both numbers of students.',
                'unit' => 'students',
                'cue' => $same,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Boys', 'value' => 24, 'traps' => [$factor(8), $part(3), $swap(40)]],
                    ['key' => 'second', 'name' => 'Girls', 'value' => 40, 'traps' => [$given(24), $factor(8), $part(5), $scale(120)]],
                ],
                'hint' => 'Boys 3x = 24, so x = 8. Girls 5x = 40.',
            ],
            [
                'story' => 'Apples to oranges is 2 : 7. There are 35 oranges. Write both numbers of fruits.',
                'unit' => 'fruits',
                'cue' => $same,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Apples', 'value' => 10, 'traps' => [$given(35), $factor(5), $part(2), $scale(70)]],
                    ['key' => 'second', 'name' => 'Oranges', 'value' => 35, 'traps' => [$factor(5), $part(7), $swap(10)]],
                ],
                'hint' => 'Oranges are the second part: 7x = 35, so x = 5. Apples 2x = 10.',
            ],
            [
                'story' => 'Rice to sugar is 2 kg : 500 g. The rice weighs 6 kg. Write both quantities in grams.',
                'unit' => 'grams',
                'cue' => $mixed,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Rice', 'value' => 6000, 'traps' => [$unit(6), $part(4), $factor(1500)]],
                    ['key' => 'second', 'name' => 'Sugar', 'value' => 1500, 'traps' => [$given(6000), $unit(6), $unit(500), $part(1)]],
                ],
                'hint' => '2 kg = 2000 g, so the ratio is 4 : 1. Rice 4x = 6000 g, x = 1500. Sugar 1x = 1500 g.',
            ],
            [
                'story' => 'Ribbon to tape is 3 m : 40 cm. The tape is 80 cm. Write both lengths in centimetres.',
                'unit' => 'cm',
                'cue' => $mixed,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Ribbon', 'value' => 600, 'traps' => [$given(80), $factor(40), $part(15), $unit(3), $scale(240)]],
                    ['key' => 'second', 'name' => 'Tape', 'value' => 80, 'traps' => [$factor(40), $part(2), $swap(600)]],
                ],
                'hint' => '3 m = 300 cm, so the ratio is 300 : 40 = 15 : 2. Tape 2x = 80, x = 40. Ribbon 15x = 600 cm.',
            ],
            [
                'story' => 'The cost of a pen to the cost of a notebook is ₹3 : 50 paise. The notebook costs ₹2.50. Write both costs in paise.',
                'unit' => 'paise',
                'cue' => $mixed,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Pen', 'value' => 1500, 'traps' => [$given(250), $unit(3), $part(6), $unit(50), $scale(750)]],
                    ['key' => 'second', 'name' => 'Notebook', 'value' => 250, 'traps' => [$unit(2), $part(1), $swap(1500)]],
                ],
                'hint' => '₹3 = 300 paise, so the ratio is 300 : 50 = 6 : 1. Notebook 1x = 250 paise, x = 250. Pen 6x = 1500 paise.',
            ],
            [
                'story' => 'The distance walked to the distance run is 4 : 9. The distance run is 18 km. Write both distances in kilometres.',
                'unit' => 'km',
                'cue' => $same,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Walked', 'value' => 8, 'traps' => [$given(18), $factor(2), $part(4), $scale(72)]],
                    ['key' => 'second', 'name' => 'Run', 'value' => 18, 'traps' => [$factor(2), $part(9), $swap(8)]],
                ],
                'hint' => 'Running is the second part: 9x = 18, so x = 2. Walked 4x = 8 km.',
            ],
            [
                'story' => 'Juice to water is 750 ml : 1 litre. There are 3 litres of water. Write both quantities in millilitres.',
                'unit' => 'ml',
                'cue' => $mixed,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Juice', 'value' => 2250, 'traps' => [$given(3000), $factor(750), $part(3), $scale(9000)]],
                    ['key' => 'second', 'name' => 'Water', 'value' => 3000, 'traps' => [$unit(3), $part(4), $factor(750)]],
                ],
                'hint' => '1 litre = 1000 ml, so the ratio is 3 : 4. Water 4x = 3000 ml, x = 750. Juice 3x = 2250 ml.',
            ],
            [
                'story' => 'Red paint to blue paint is 2 : 5. The blue paint is 1 kg 250 g. Write both quantities in grams.',
                'unit' => 'grams',
                'cue' => $mixed,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Red paint', 'value' => 500, 'traps' => [$given(1250), $factor(250), $part(2), $unit(1), $scale(2500)]],
                    ['key' => 'second', 'name' => 'Blue paint', 'value' => 1250, 'traps' => [$unit(1), $part(5), $factor(250)]],
                ],
                'hint' => '1 kg 250 g = 1250 g. Blue paint 5x = 1250, x = 250. Red paint 2x = 500 g.',
            ],
            [
                'story' => 'Study time to sleep time is 2 hours : 45 minutes. The study time is 4 hours. Write both times in minutes.',
                'unit' => 'minutes',
                'cue' => $mixed,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Study', 'value' => 240, 'traps' => [$unit(4), $unit(2), $part(8), $factor(30)]],
                    ['key' => 'second', 'name' => 'Sleep', 'value' => 90, 'traps' => [$given(240), $factor(30), $part(3), $unit(45), $scale(180)]],
                ],
                'hint' => '2 hours = 120 minutes, so the ratio is 120 : 45 = 8 : 3. Study 8x = 240 minutes, x = 30. Sleep 3x = 90 minutes.',
            ],
            [
                'story' => 'Copper to zinc in an alloy is 7 : 3. The copper is 2 kg 100 g. Write both quantities in grams.',
                'unit' => 'grams',
                'cue' => $mixed,
                'quantities' => [
                    ['key' => 'first', 'name' => 'Copper', 'value' => 2100, 'traps' => [$unit(2), $part(7), $factor(300)]],
                    ['key' => 'second', 'name' => 'Zinc', 'value' => 900, 'traps' => [$given(2100), $factor(300), $part(3), $unit(2), $scale(6300)]],
                ],
                'hint' => '2 kg 100 g = 2100 g. Copper 7x = 2100, x = 300. Zinc 3x = 900 g.',
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
            if (($answers['play']['kind'] ?? '') === 'given_part') {
                DB::table('mmm_card_exercises')->where('id', $row->id)->delete();
            }
        }
    }
};
