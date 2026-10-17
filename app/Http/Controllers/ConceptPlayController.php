<?php

namespace App\Http\Controllers;

use App\Models\TextbookChapter;
use App\Services\ConceptPathService;
use App\Support\ConceptPathStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Run-only concept path for every signed-in role (no create / edit).
 */
class ConceptPlayController extends Controller
{
    public function __construct(
        private ConceptPathService $conceptPath,
    ) {}

    public function play(Request $request, TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        $textbookChapter->load([
            'textbook.gradeLevel:id,name',
            'syllabusChapter:id,name,chapter_number',
        ]);

        $items = is_array($textbookChapter->concept_path_items) ? $textbookChapter->concept_path_items : [];
        $allCards = is_array($items['cards'] ?? null) ? $items['cards'] : [];
        $includedCards = array_values(array_filter(
            $allCards,
            fn ($card) => is_array($card) && ($card['approved'] ?? true),
        ));

        if ($includedCards === []) {
            return redirect()
                ->route('admin.concept-overview.index')
                ->with('error', 'This concept path has no cards to run yet.');
        }

        if ($textbookChapter->concept_path_status !== ConceptPathStatus::APPROVED) {
            return redirect()
                ->route('admin.concept-overview.index')
                ->with('error', 'This concept path is not ready to run yet.');
        }

        $cards = $this->conceptPath->withDiagramUrls($includedCards);
        $playUrl = route('concepts.play', $textbookChapter);
        $overviewUrl = route('admin.concept-overview.index');

        return Inertia::render('Admin/Textbooks/ConceptPathPlay', [
            'uploaderMode' => false,
            'runOnly' => true,
            'singleCard' => false,
            'jobCompletedFlash' => null,
            'chapter' => [
                'id' => $textbookChapter->id,
                'label' => $textbookChapter->displaySyllabusLabel(),
                'title' => $textbookChapter->displayTitle(),
                'chapter_number' => $textbookChapter->displayChapterNumber(),
                'book_name' => $textbookChapter->textbook?->name,
                'book_code' => $textbookChapter->textbook?->code,
                'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                'builder_url' => null,
                'edit_url' => $overviewUrl,
                'play_url' => $playUrl,
                'can_run_full' => true,
                'overview_url' => $overviewUrl,
            ],
            'path' => [
                'chapter_title' => $items['chapter_title'] ?? $textbookChapter->displayTitle(),
                'status' => $textbookChapter->concept_path_status,
                'status_label' => ConceptPathStatus::label($textbookChapter->concept_path_status),
                'cards' => array_values(array_map(function (array $card, int $index) {
                    $card['step'] = $index + 1;

                    return $card;
                }, $cards, array_keys($cards))),
            ],
        ]);
    }
}
