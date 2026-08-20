<?php

namespace Tests\Unit;

use App\Support\PracticeSetMasterProfile;
use App\Support\PracticeSetTier;
use Tests\TestCase;

class PracticeSetMasterProfileTest extends TestCase
{
    public function test_learner_achiever_expert_counts_match_master(): void
    {
        $this->assertSame(
            ['total' => 20, 'easy' => 15, 'medium' => 5, 'hard' => 0],
            PracticeSetMasterProfile::counts(PracticeSetTier::STARTER),
        );

        $this->assertSame(
            ['total' => 20, 'easy' => 5, 'medium' => 13, 'hard' => 2],
            PracticeSetMasterProfile::counts(PracticeSetTier::BUILDER),
        );

        $this->assertSame(
            ['total' => 15, 'easy' => 0, 'medium' => 0, 'hard' => 15],
            PracticeSetMasterProfile::counts(PracticeSetTier::CHAMPION),
        );
    }

    public function test_marks_master_and_score(): void
    {
        $this->assertSame(['easy' => 1, 'medium' => 2, 'hard' => 3], PracticeSetMasterProfile::marks());
        $this->assertSame(19, PracticeSetMasterProfile::score(3, 5, 2));
        $this->assertSame(25, PracticeSetMasterProfile::score(15, 5, 0));
    }

    public function test_tier_from_difficulty_counts_prefers_highest_with_hard_tiebreak(): void
    {
        $this->assertSame(PracticeSetTier::STARTER, PracticeSetMasterProfile::tierFromDifficultyCounts(10, 2, 1));
        $this->assertSame(PracticeSetTier::BUILDER, PracticeSetMasterProfile::tierFromDifficultyCounts(2, 10, 1));
        $this->assertSame(PracticeSetTier::CHAMPION, PracticeSetMasterProfile::tierFromDifficultyCounts(1, 2, 10));
        $this->assertSame(PracticeSetTier::CHAMPION, PracticeSetMasterProfile::tierFromDifficultyCounts(5, 5, 5));
        $this->assertSame(PracticeSetTier::BUILDER, PracticeSetMasterProfile::tierFromDifficultyCounts(5, 5, 0));
    }

    public function test_distribute_across_topics_sums_to_profile(): void
    {
        $rows = PracticeSetMasterProfile::distributeAcrossTopics(PracticeSetTier::STARTER, 4);

        $this->assertCount(4, $rows);
        $this->assertSame(15, collect($rows)->sum('easy'));
        $this->assertSame(5, collect($rows)->sum('medium'));
        $this->assertSame(0, collect($rows)->sum('hard'));
    }

    public function test_tier_labels_are_learner_achiever_expert(): void
    {
        $this->assertSame('Learner', PracticeSetTier::label(PracticeSetTier::STARTER));
        $this->assertSame('Achiever', PracticeSetTier::label(PracticeSetTier::BUILDER));
        $this->assertSame('Expert', PracticeSetTier::label(PracticeSetTier::CHAMPION));
    }
}
