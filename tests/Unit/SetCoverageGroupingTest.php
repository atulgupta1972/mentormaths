<?php

namespace Tests\Unit;

use App\Services\SetCoverageGrouping;
use Tests\TestCase;

class SetCoverageGroupingTest extends TestCase
{
    public function test_book_content_is_grouped_by_textbook_name(): void
    {
        $dashboard = (new SetCoverageGrouping)->formatDashboard([
            'books' => [
                '11' => [
                    [
                        'worksheet_id' => 1,
                        'short_label' => 'B1',
                        'status' => 'done',
                        'textbook_id' => 11,
                        'textbook_name' => 'Ganita Prakash',
                    ],
                ],
                '22' => [
                    [
                        'worksheet_id' => 2,
                        'short_label' => 'B2',
                        'status' => 'not_assigned',
                        'textbook_id' => 22,
                        'textbook_name' => 'NCERT',
                    ],
                ],
            ],
        ]);

        $this->assertCount(2, $dashboard['book_groups']);
        $this->assertSame('Ganita Prakash', $dashboard['book_groups'][0]['name']);
        $this->assertSame('NCERT', $dashboard['book_groups'][1]['name']);
        $this->assertCount(2, $dashboard['books']['items']);
    }

    public function test_other_groups_are_formatted_for_dashboard(): void
    {
        $dashboard = (new SetCoverageGrouping)->formatDashboard([
            'other' => [
                [
                    'id' => 'sc:9',
                    'label' => 'Class 6 - Decimals',
                    'items' => [
                        [
                            'worksheet_id' => 50,
                            'short_label' => 'P1',
                            'status' => 'pending',
                            'tier' => 'starter',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $dashboard['other_groups']);
        $this->assertSame('Class 6 - Decimals', $dashboard['other_groups'][0]['label']);
        $this->assertCount(1, $dashboard['other']['items']);
    }
}
