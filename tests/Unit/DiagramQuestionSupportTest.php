<?php

namespace Tests\Unit;

use App\Models\SyllabusChapter;
use App\Support\DiagramQuestionSupport;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DiagramQuestionSupportTest extends TestCase
{
    public function test_detects_geometry_chapter_from_name(): void
    {
        $chapter = new SyllabusChapter(['name' => 'Lines and Angles']);
        $chapter->setRelation('topics', Collection::make());

        $this->assertTrue(DiagramQuestionSupport::looksLikeGeometryChapter($chapter));
    }

    public function test_ignores_algebra_chapter_for_diagram_expectation(): void
    {
        $chapter = new SyllabusChapter(['name' => 'Algebraic Expressions']);
        $chapter->setRelation('topics', Collection::make());

        $item = [
            'question' => 'In the figure, simplify 2x + 3x = ____',
            'needs_diagram' => true,
        ];

        $this->assertFalse(DiagramQuestionSupport::shouldExpectDiagram($item, $chapter));
    }

    public function test_needs_diagram_from_question_text(): void
    {
        $item = ['question' => 'In the figure, find x.'];

        $this->assertTrue(DiagramQuestionSupport::needsDiagram($item));
    }
}
