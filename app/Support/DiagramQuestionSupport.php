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

        if (filled($item['diagram_file'] ?? null) || filled($item['diagram_staging_path'] ?? null)) {
            return true;
        }

        $question = mb_strtolower(trim((string) ($item['question'] ?? $item['question_text'] ?? '')));
        $chart = mb_strtolower(trim((string) ($item['chart'] ?? '')));

        foreach ([$question, $chart] as $haystack) {
            if ($haystack === '') {
                continue;
            }

            if (str_contains($haystack, 'in the figure')
                || str_contains($haystack, 'in the diagram')
                || str_contains($haystack, 'in fig.')
                || str_contains($haystack, 'see fig')
                || str_contains($haystack, 'fig.')
                || str_contains($haystack, 'requires a figure upload')
                || str_contains($haystack, 'number line')
                || str_contains($haystack, 'bar graph')
                || str_contains($haystack, 'pie chart')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public static function shouldExpectDiagram(array $item, ?SyllabusChapter $chapter): bool
    {
        return self::needsDiagram($item) && self::looksLikeGeometryChapter($chapter);
    }
}
