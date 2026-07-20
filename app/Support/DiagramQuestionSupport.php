<?php

namespace App\Support;

use App\Models\SyllabusChapter;

class DiagramQuestionSupport
{
    /**
     * @var list<string>
     */
    private const GEOMETRY_KEYWORDS = [
        'angle',
        'line',
        'triangle',
        'circle',
        'quadrilateral',
        'polygon',
        'parallel',
        'transversal',
        'geometry',
        'congruent',
        'symmetry',
        'construction',
        'coordinate geometry',
        'mensuration',
        'perpendicular',
    ];

    public static function looksLikeGeometryChapter(?SyllabusChapter $chapter): bool
    {
        if (! $chapter) {
            return false;
        }

        $chapter->loadMissing('topics');

        $haystack = mb_strtolower(trim(
            $chapter->name.' '.$chapter->topics->pluck('name')->implode(' '),
        ));

        foreach (self::GEOMETRY_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function needsDiagram(array $item): bool
    {
        $flag = $item['needs_diagram'] ?? $item['with_figure'] ?? null;

        if ($flag !== null && filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $question = mb_strtolower(trim((string) ($item['question'] ?? $item['question_text'] ?? '')));

        return str_contains($question, 'in the figure')
            || str_contains($question, 'in the diagram')
            || str_contains($question, 'in fig.');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function shouldExpectDiagram(array $item, ?SyllabusChapter $chapter): bool
    {
        return self::needsDiagram($item) && self::looksLikeGeometryChapter($chapter);
    }
}
