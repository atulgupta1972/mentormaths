<?php

namespace Tests\Unit;

use App\Support\ProgressSummaryTable;
use Tests\TestCase;

class ProgressSummaryTableTest extends TestCase
{
    public function test_groups_rows_by_chapter_and_sorts_chapters_by_earliest_date(): void
    {
        $rows = [
            [
                'chapter_name' => 'Algebra',
                'set_code' => 'S903',
                'submitted_at' => '2026-07-10 12:00:00',
            ],
            [
                'chapter_name' => 'Fractions',
                'set_code' => 'S901',
                'submitted_at' => '2026-07-05 10:00:00',
            ],
            [
                'chapter_name' => 'Fractions',
                'set_code' => 'S902',
                'submitted_at' => '2026-07-08 15:00:00',
            ],
            [
                'set_code' => 'S900',
                'submitted_at' => '2026-07-01 09:00:00',
            ],
        ];

        $groups = ProgressSummaryTable::groupByChapter($rows, 'submitted_at');

        $this->assertSame(['Other', 'Fractions', 'Algebra'], array_column($groups, 'chapter_name'));
        $this->assertSame(['S900'], array_column($groups[0]['rows'], 'set_code'));
        $this->assertSame(['S901', 'S902'], array_column($groups[1]['rows'], 'set_code'));
        $this->assertSame(['S903'], array_column($groups[2]['rows'], 'set_code'));
    }

    public function test_detail_label_prefers_topic_name(): void
    {
        $this->assertSame(
            'Integers on number line',
            ProgressSummaryTable::detailLabel([
                'topic_name' => 'Integers on number line',
                'display_title' => 'Set 1',
                'kind_label' => 'Practice',
            ]),
        );
    }
}
