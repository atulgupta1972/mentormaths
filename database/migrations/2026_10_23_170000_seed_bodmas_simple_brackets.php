<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'bodmas-simple-brackets')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'bodmas-simple-brackets',
            'title' => 'BODMAS — simple brackets',
            'heading' => 'BODMAS — simple brackets',
            'kind' => 'bodmas',
            'status' => 'published',
            'accent' => 'amber',
            'method_blurb' => 'Brackets come first. Solve inside ( ), then open the bracket. If there is no bracket, simplify each division, then multiply, then add and subtract from the left.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 9,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$this->simpleBracket(), $this->integerBodmas(), $this->bracketBeforeDivision(), $this->practice()] as $exercise) {
            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => $exercise['sort_order'],
                'difficulty' => $exercise['difficulty'],
                'is_demo' => $exercise['is_demo'],
                'question_text' => $exercise['question_text'],
                'answers' => json_encode($exercise['answers']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sourceIds = DB::table('mmm_cards')
            ->whereIn('slug', [
                'balance-the-equation',
                'balance-equation-basic-2',
                'balance-equation-basic-3',
                'balance-equation-basic-4',
                'balance-equation-basic-5',
                'balance-equation-basic-6',
            ])
            ->pluck('id');
        $chapterIds = DB::table('mmm_card_syllabus_chapter')
            ->whereIn('mmm_card_id', $sourceIds)
            ->pluck('syllabus_chapter_id')
            ->unique();
        foreach ($chapterIds as $chapterId) {
            DB::table('mmm_card_syllabus_chapter')->insert([
                'mmm_card_id' => $cardId,
                'syllabus_chapter_id' => $chapterId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'bodmas-simple-brackets')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('student_mmm_progress')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_card_syllabus_chapter')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }

    /**
     * (12 + 3) × 2. Tap the bracket, solve inside, open, then multiply.
     *
     * @return array<string, mixed>
     */
    private function simpleBracket(): array
    {
        return [
            'sort_order' => 1,
            'difficulty' => 'guided',
            'is_demo' => true,
            'question_text' => 'A bracket is here. Solve inside ( ), then open it.',
            'answers' => [
                'mode' => 'bodmas',
                'check' => ['in' => '15', 'ans' => '30'],
                'play' => [
                    'expression' => '(12 + 3) × 2',
                    'tally' => '30',
                    'steps' => [
                        [
                            'key' => 'tap',
                            'type' => 'tap',
                            'letter' => 'B',
                            'prompt' => 'Tap the part you do first.',
                            'hits' => ['br'],
                            'pieces' => [
                                ['id' => 'br', 'text' => '(12 + 3)'],
                                ['text' => '×'],
                                ['id' => 'two', 'text' => '2'],
                            ],
                            'miss' => 'Solve inside the bracket before you multiply.',
                            'ok_message' => 'Yes. The bracket comes first.',
                            'done' => 'Bracket first: (12 + 3)',
                        ],
                        [
                            'key' => 'inside',
                            'type' => 'fill',
                            'letter' => 'B',
                            'prompt' => 'Solve inside the bracket.',
                            'pieces' => [
                                ['text' => '(12 + 3) ='],
                                ['box' => 'in', 'label' => 'inside the bracket'],
                            ],
                            'miss' => '12 + 3 is 15.',
                            'ok_message' => 'Inside is 15. Now open the bracket.',
                            'done' => '(12 + 3) = 15',
                        ],
                        [
                            'key' => 'open',
                            'type' => 'next',
                            'letter' => 'B',
                            'prompt' => 'Open the bracket. The 15 stays, and the brackets go.',
                            'button' => 'Bracket is open',
                            'value' => 'ok',
                            'pieces' => [
                                ['text' => '15 × 2'],
                            ],
                            'ok_message' => 'The bracket is open. Multiply.',
                            'done' => 'Open: 15 × 2',
                        ],
                        [
                            'key' => 'times',
                            'type' => 'fill',
                            'letter' => 'M',
                            'prompt' => 'Multiply.',
                            'pieces' => [
                                ['text' => '15 × 2 ='],
                                ['box' => 'ans', 'label' => '15 times 2'],
                            ],
                            'miss' => '15 × 2 = 30.',
                            'ok_message' => '30. The bracket is done.',
                            'done' => '15 × 2 = 30',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Notebook sum: 25 ÷ 5 × 7 + 2 − 9 ÷ 3 = 34.
     *
     * @return array<string, mixed>
     */
    private function integerBodmas(): array
    {
        return [
            'sort_order' => 2,
            'difficulty' => 'guided',
            'is_demo' => true,
            'question_text' => 'No brackets. Mark each division, rewrite it as a fraction, then simplify.',
            'answers' => [
                'mode' => 'bodmas',
                'check' => [
                    'left' => '5',
                    'right' => '3',
                    'prod' => '35',
                    'sum' => '37',
                    'ans' => '34',
                ],
                'play' => [
                    'expression' => '25 ÷ 5 × 7 + 2 − 9 ÷ 3',
                    'tally' => '34',
                    'steps' => [
                        [
                            'key' => 'divisions',
                            'type' => 'tap',
                            'letter' => 'D',
                            'prompt' => 'No brackets. Tap each division.',
                            'hits' => ['d1', 'd2'],
                            'pieces' => [
                                ['id' => 'd1', 'text' => '25 ÷ 5'],
                                ['text' => '×'],
                                ['id' => 'seven', 'text' => '7'],
                                ['text' => '+'],
                                ['id' => 'two', 'text' => '2'],
                                ['text' => '−'],
                                ['id' => 'd2', 'text' => '9 ÷ 3'],
                            ],
                            'miss' => 'Division is the D in BODMAS. Tap both divisions.',
                            'ok_message' => 'Both divisions are marked.',
                            'done' => 'Divisions: 25 ÷ 5 and 9 ÷ 3',
                        ],
                        [
                            'key' => 'rewrite',
                            'type' => 'next',
                            'letter' => 'D',
                            'prompt' => 'Rewrite each division as a fraction.',
                            'button' => 'Simplify the divisions',
                            'value' => 'ok',
                            'pieces' => [
                                ['num' => '25', 'den' => '5'],
                                ['text' => '× 7 + 2 −'],
                                ['num' => '9', 'den' => '3'],
                            ],
                            'ok_message' => 'Now simplify those divisions only.',
                            'done_pieces' => [
                                ['num' => '25', 'den' => '5'],
                                ['text' => '× 7 + 2 −'],
                                ['num' => '9', 'den' => '3'],
                            ],
                        ],
                        [
                            'key' => 'quotients',
                            'type' => 'fill',
                            'letter' => 'D',
                            'prompt' => 'Simplify the divisions only.',
                            'above' => [
                                ['num' => '25', 'den' => '5'],
                                ['text' => '× 7 + 2 −'],
                                ['num' => '9', 'den' => '3'],
                            ],
                            'pieces' => [
                                ['box' => 'left', 'label' => '25 divided by 5'],
                                ['text' => '× 7 + 2 −'],
                                ['box' => 'right', 'label' => '9 divided by 3'],
                            ],
                            'miss' => '25 ÷ 5 is 5, and 9 ÷ 3 is 3.',
                            'ok_message' => 'Divisions are done. Multiply next.',
                            'done' => '5 × 7 + 2 − 3',
                        ],
                        [
                            'key' => 'times',
                            'type' => 'tap',
                            'letter' => 'M',
                            'prompt' => 'Tap the multiplication.',
                            'hits' => ['mul'],
                            'pieces' => [
                                ['id' => 'mul', 'text' => '5 × 7'],
                                ['text' => '+'],
                                ['id' => 'two', 'text' => '2'],
                                ['text' => '−'],
                                ['id' => 'three', 'text' => '3'],
                            ],
                            'miss' => 'Multiplication comes before addition.',
                            'ok_message' => 'Yes. 5 × 7 is next.',
                            'done' => 'Multiply 5 × 7',
                        ],
                        [
                            'key' => 'product',
                            'type' => 'fill',
                            'letter' => 'M',
                            'prompt' => 'Multiply.',
                            'pieces' => [
                                ['text' => '5 × 7 ='],
                                ['box' => 'prod', 'label' => '5 times 7'],
                            ],
                            'miss' => '5 × 7 = 35.',
                            'ok_message' => '35 + 2 − 3. Add and subtract from the left.',
                            'done' => '35 + 2 − 3',
                        ],
                        [
                            'key' => 'add',
                            'type' => 'fill',
                            'letter' => 'A',
                            'prompt' => 'From the left: 35 + 2.',
                            'pieces' => [
                                ['text' => '35 + 2 ='],
                                ['box' => 'sum', 'label' => '35 plus 2'],
                            ],
                            'miss' => '35 + 2 = 37. The − 3 is still waiting.',
                            'ok_message' => '37. Now subtract 3.',
                            'done' => '37 − 3',
                        ],
                        [
                            'key' => 'last',
                            'type' => 'fill',
                            'letter' => 'S',
                            'prompt' => 'Subtract.',
                            'pieces' => [
                                ['text' => '37 − 3 ='],
                                ['box' => 'ans', 'label' => '37 minus 3'],
                            ],
                            'miss' => '37 − 3 = 34.',
                            'ok_message' => '34.',
                            'done' => '34',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 25 ÷ 5 × (7 + 2) − 9 ÷ 3. The bracket wins over the divisions.
     *
     * @return array<string, mixed>
     */
    private function bracketBeforeDivision(): array
    {
        return [
            'sort_order' => 3,
            'difficulty' => 'guided',
            'is_demo' => true,
            'question_text' => 'The bracket is done before any division outside it.',
            'answers' => [
                'mode' => 'bodmas',
                'check' => [
                    'in' => '9',
                    'left' => '5',
                    'right' => '3',
                    'prod' => '45',
                    'ans' => '42',
                ],
                'play' => [
                    'expression' => '25 ÷ 5 × (7 + 2) − 9 ÷ 3',
                    'tally' => '42',
                    'steps' => [
                        [
                            'key' => 'bracket',
                            'type' => 'tap',
                            'letter' => 'B',
                            'prompt' => 'Tap the part you do first.',
                            'hits' => ['br'],
                            'pieces' => [
                                ['id' => 'd1', 'text' => '25 ÷ 5'],
                                ['text' => '×'],
                                ['id' => 'br', 'text' => '(7 + 2)'],
                                ['text' => '−'],
                                ['id' => 'd2', 'text' => '9 ÷ 3'],
                            ],
                            'miss' => 'The bracket comes before those divisions.',
                            'ok_message' => 'Yes. Solve inside (7 + 2) first.',
                            'done' => 'Bracket first: (7 + 2)',
                        ],
                        [
                            'key' => 'inside',
                            'type' => 'fill',
                            'letter' => 'B',
                            'prompt' => 'Solve inside the bracket.',
                            'pieces' => [
                                ['text' => '(7 + 2) ='],
                                ['box' => 'in', 'label' => 'inside the bracket'],
                            ],
                            'miss' => '7 + 2 is 9.',
                            'ok_message' => 'Inside is 9. Open the bracket.',
                            'done' => '(7 + 2) = 9',
                        ],
                        [
                            'key' => 'open',
                            'type' => 'next',
                            'letter' => 'B',
                            'prompt' => 'Open the bracket. The line has no brackets now.',
                            'button' => 'Bracket is open',
                            'value' => 'ok',
                            'pieces' => [
                                ['text' => '25 ÷ 5 × 9 − 9 ÷ 3'],
                            ],
                            'ok_message' => 'No brackets left. Find the divisions.',
                            'done' => 'Open: 25 ÷ 5 × 9 − 9 ÷ 3',
                        ],
                        [
                            'key' => 'divisions',
                            'type' => 'tap',
                            'letter' => 'D',
                            'prompt' => 'Tap each division.',
                            'hits' => ['d1', 'd2'],
                            'pieces' => [
                                ['id' => 'd1', 'text' => '25 ÷ 5'],
                                ['text' => '×'],
                                ['id' => 'nine', 'text' => '9'],
                                ['text' => '−'],
                                ['id' => 'd2', 'text' => '9 ÷ 3'],
                            ],
                            'miss' => 'Tap both divisions. The lone 9 is not a division.',
                            'ok_message' => 'Both divisions are marked.',
                            'done' => 'Divisions: 25 ÷ 5 and 9 ÷ 3',
                        ],
                        [
                            'key' => 'quotients',
                            'type' => 'fill',
                            'letter' => 'D',
                            'prompt' => 'Simplify the divisions only.',
                            'above' => [
                                ['num' => '25', 'den' => '5'],
                                ['text' => '× 9 −'],
                                ['num' => '9', 'den' => '3'],
                            ],
                            'pieces' => [
                                ['box' => 'left', 'label' => '25 divided by 5'],
                                ['text' => '× 9 −'],
                                ['box' => 'right', 'label' => '9 divided by 3'],
                            ],
                            'miss' => '25 ÷ 5 is 5, and 9 ÷ 3 is 3.',
                            'ok_message' => '5 × 9 − 3. Multiply next.',
                            'done' => '5 × 9 − 3',
                        ],
                        [
                            'key' => 'times',
                            'type' => 'tap',
                            'letter' => 'M',
                            'prompt' => 'Tap the multiplication.',
                            'hits' => ['mul'],
                            'pieces' => [
                                ['id' => 'mul', 'text' => '5 × 9'],
                                ['text' => '−'],
                                ['id' => 'three', 'text' => '3'],
                            ],
                            'miss' => 'Multiply before you subtract.',
                            'ok_message' => 'Yes. 5 × 9 is next.',
                            'done' => 'Multiply 5 × 9',
                        ],
                        [
                            'key' => 'product',
                            'type' => 'fill',
                            'letter' => 'M',
                            'prompt' => 'Multiply.',
                            'pieces' => [
                                ['text' => '5 × 9 ='],
                                ['box' => 'prod', 'label' => '5 times 9'],
                            ],
                            'miss' => '5 × 9 = 45.',
                            'ok_message' => '45 − 3. Subtract.',
                            'done' => '45 − 3',
                        ],
                        [
                            'key' => 'last',
                            'type' => 'fill',
                            'letter' => 'S',
                            'prompt' => 'Subtract.',
                            'pieces' => [
                                ['text' => '45 − 3 ='],
                                ['box' => 'ans', 'label' => '45 minus 3'],
                            ],
                            'miss' => '45 − 3 = 42.',
                            'ok_message' => '42.',
                            'done' => '42',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function practice(): array
    {
        $items = [
            ['key' => 'q1', 'equation' => '(8 + 4) × 2', 'answer' => '24'],
            ['key' => 'q2', 'equation' => '20 − (6 + 3)', 'answer' => '11'],
            ['key' => 'q3', 'equation' => '(18 − 6) ÷ 3', 'answer' => '4'],
            ['key' => 'q4', 'equation' => '4 × (5 + 3) − 2', 'answer' => '30'],
            ['key' => 'q5', 'equation' => '(25 ÷ 5) × 7 + 2', 'answer' => '37'],
            ['key' => 'q6', 'equation' => '(12 + 8) ÷ (2 + 3)', 'answer' => '4'],
            ['key' => 'q7', 'equation' => '36 ÷ (3 × 2) + 1', 'answer' => '7'],
            ['key' => 'q8', 'equation' => '(9 + 6) ÷ 3 + 2', 'answer' => '7'],
        ];
        $check = [];
        $playItems = [];
        foreach ($items as $item) {
            $check[$item['key']] = $item['answer'];
            $playItems[] = [
                'key' => $item['key'],
                'equation' => $item['equation'],
                'label' => '=',
            ];
        }

        return [
            'sort_order' => 4,
            'difficulty' => 'practice',
            'is_demo' => false,
            'question_text' => 'Solve inside each ( ) first, then finish the line.',
            'answers' => [
                'mode' => 'fill_page',
                'variable_tip' => 'Solve inside ( ) first. Then division and multiplication, then add and subtract from the left.',
                'check' => $check,
                'play' => [
                    'page_blurb' => 'Eight sums with simple brackets.',
                    'items' => $playItems,
                ],
            ],
        ];
    }
};
