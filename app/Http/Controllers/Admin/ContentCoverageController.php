<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Services\AdminChapterContentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentCoverageController extends Controller
{
    public function __construct(private AdminChapterContentService $contentService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->isAdmin() || $user?->isMentor(), 403);

        $activeYear = AcademicYear::active();
        $filters = [
            'grade_levels' => [],
            'boards_by_grade' => [],
            'selected_grade_level_id' => null,
            'selected_board_id' => null,
        ];
        $coverage = [
            'book_columns' => [],
            'chapters' => [],
            'context' => [],
        ];

        if ($activeYear) {
            $filters = $this->contentService->filterOptions(
                $activeYear,
                $request->integer('grade_level_id') ?: null,
                $request->integer('board_id') ?: null,
            );

            if ($filters['selected_grade_level_id'] && $filters['selected_board_id']) {
                $coverage = $this->contentService->forClassAndBoard(
                    $activeYear,
                    $filters['selected_grade_level_id'],
                    $filters['selected_board_id'],
                );
            }
        }

        $browseOnly = ! $user->isAdmin();

        return Inertia::render('Admin/Questions/ContentCoverage', [
            'activeYear' => $activeYear?->only(['id', 'name']),
            'coverage' => $coverage,
            'coverageFilters' => $filters,
            'browseOnly' => $browseOnly,
        ]);
    }
}
