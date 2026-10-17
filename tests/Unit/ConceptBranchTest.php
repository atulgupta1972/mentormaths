<?php

namespace Tests\Unit;

use App\Support\ConceptBranch;
use PHPUnit\Framework\TestCase;

class ConceptBranchTest extends TestCase
{
    public function test_guesses_common_chapter_head_names(): void
    {
        $this->assertSame('algebra', ConceptBranch::guessFromName('Algebraic Expressions'));
        $this->assertSame('geometry', ConceptBranch::guessFromName('Triangles'));
        $this->assertSame('mensuration', ConceptBranch::guessFromName('Surface Areas and Volumes'));
        $this->assertSame('trigonometry', ConceptBranch::guessFromName('Introduction to Trigonometry'));
        $this->assertSame('number_system', ConceptBranch::guessFromName('Integers'));
        $this->assertSame('commercial', ConceptBranch::guessFromName('Profit and Loss'));
        $this->assertSame('statistics', ConceptBranch::guessFromName('Data Handling'));
    }
}
