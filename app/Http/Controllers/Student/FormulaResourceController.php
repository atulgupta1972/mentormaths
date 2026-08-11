<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SyllabusChapter;
use App\Services\ExamPlanService;
use App\Services\FormulaBankService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FormulaResourceController extends Controller
{
    public function __construct(
        private FormulaBankService $formulaBank,
        private ExamPlanService $examPlanService,
    ) {}

    public function index(Request $request): Response
    {
        $enrollment = $request->user()->student?->currentEnrollment();
        abort_unless($enrollment, 403, 'No active enrollment for this year.');

        $enrollment->loadMissing(['gradeLevel:id,name', 'board:id,code,name']);

        $chapters = $this->formulaBank->chaptersForClass(
            $enrollment->gradeLevel,
            $enrollment->board,
        );

        return Inertia::render('Student/Resources/Formulas/Index', [
            'context' => [
                'grade_name' => $enrollment->gradeLevel?->name,
                'board_name' => $enrollment->board?->name,
                'board_code' => $enrollment->board?->code,
            ],
            'chapters' => collect($chapters)->map(fn (array $chapter) => [
                'id' => $chapter['id'],
                'name' => $chapter['name'],
                'sort_order' => $chapter['sort_order'],
                'formulas_count' => $chapter['formulas_count'],
                'topics_count' => $chapter['topics_count'],
            ])->values()->all(),
            'total_formulas' => collect($chapters)->sum('formulas_count'),
        ]);
    }

    public function chapter(Request $request, SyllabusChapter $syllabusChapter): Response
    {
        $enrollment = $request->user()->student?->currentEnrollment();
        abort_unless($enrollment, 403, 'No active enrollment for this year.');

        $allowedIds = $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->pluck('id')
            ->all();

        abort_unless(in_array($syllabusChapter->id, $allowedIds, true), 403);

        $syllabusChapter->loadMissing([
            'syllabusVersion.gradeLevel:id,name',
            'syllabusVersion.board:id,code,name',
        ]);

        $cards = $this->formulaBank->chapterCards($syllabusChapter);
        $byTopic = collect($cards)
            ->groupBy(fn (array $card) => $card['topic_name'] ?: 'General')
            ->map(fn ($group, $topicName) => [
                'topic_name' => $topicName,
                'cards' => $group->values()->all(),
            ])
            ->values()
            ->all();

        return Inertia::render('Student/Resources/Formulas/Chapter', [
            'chapter' => [
                'id' => $syllabusChapter->id,
                'name' => $syllabusChapter->name,
                'chapter_number' => $syllabusChapter->chapter_number,
            ],
            'context' => [
                'grade_name' => $syllabusChapter->syllabusVersion?->gradeLevel?->name,
                'board_name' => $syllabusChapter->syllabusVersion?->board?->name,
                'board_code' => $syllabusChapter->syllabusVersion?->board?->code,
            ],
            'formulas_count' => count($cards),
            'topics' => $byTopic,
        ]);
    }
}
