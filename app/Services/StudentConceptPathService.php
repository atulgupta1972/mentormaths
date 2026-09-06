<?php

namespace App\Services;

use App\Models\StudentConceptPathProgress;
use App\Models\StudentEnrollment;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ConceptPathStatus;
use InvalidArgumentException;

class StudentConceptPathService
{
    public function __construct(
        private ConceptPathService $conceptPath,
        private StudentChapterSummaryService $chapterSummary,
    ) {}

    /**
     * @param  list<int>  $syllabusChapterIds
     * @return array<int, array<string, mixed>> keyed by syllabus_chapter_id
     */
    public function learnCtasForSyllabusChapters(User $user, ?StudentEnrollment $enrollment, array $syllabusChapterIds): array
    {
        if ($syllabusChapterIds === []) {
            return [];
        }

        $chapters = TextbookChapter::query()
            ->with(['textbook:id,name,code'])
            ->whereIn('syllabus_chapter_id', $syllabusChapterIds)
            ->where('concept_path_status', ConceptPathStatus::APPROVED)
            ->orderBy('chapter_number')
            ->get();

        if ($chapters->isEmpty()) {
            return [];
        }

        $progress = StudentConceptPathProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('textbook_chapter_id', $chapters->pluck('id'))
            ->get()
            ->keyBy('textbook_chapter_id');

        $out = [];

        foreach ($chapters->groupBy('syllabus_chapter_id') as $syllabusId => $group) {
            /** @var TextbookChapter $tc */
            $tc = $group->first();
            $payload = $this->ctaPayload($tc, $progress->get($tc->id));
            if ($payload) {
                $out[(int) $syllabusId] = $payload;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function ctaPayload(TextbookChapter $chapter, ?StudentConceptPathProgress $progress = null): ?array
    {
        if ($chapter->concept_path_status !== ConceptPathStatus::APPROVED) {
            return null;
        }

        $cards = $this->approvedCards($chapter);
        if ($cards === []) {
            return null;
        }

        $status = 'not_started';
        if ($progress?->isCompleted()) {
            $status = 'completed';
        } elseif ($progress) {
            $status = 'in_progress';
        }

        return [
            'textbook_chapter_id' => $chapter->id,
            'title' => is_array($chapter->concept_path_items)
                ? (string) ($chapter->concept_path_items['chapter_title'] ?? $chapter->displayTitle())
                : $chapter->displayTitle(),
            'book_name' => $chapter->textbook?->name,
            'cards_total' => count($cards),
            'cards_completed' => (int) ($progress?->cards_completed ?? 0),
            'status' => $status,
            'completed_at' => $progress?->completed_at?->toIso8601String(),
            'learn_url' => route('student.concept-path.show', $chapter),
        ];
    }

    public function assertStudentCanAccess(User $user, TextbookChapter $chapter): void
    {
        if (! $user->isStudent()) {
            throw new InvalidArgumentException('Only students can learn concept paths here.');
        }

        if ($chapter->concept_path_status !== ConceptPathStatus::APPROVED) {
            throw new InvalidArgumentException('Concepts for this chapter are not ready yet.');
        }

        if ($this->approvedCards($chapter) === []) {
            throw new InvalidArgumentException('No concept cards are available for this chapter.');
        }

        $enrollment = $user->student?->currentEnrollment();
        if (! $enrollment) {
            throw new InvalidArgumentException('No active enrollment for this year.');
        }

        if (! $chapter->syllabus_chapter_id) {
            throw new InvalidArgumentException('This concept path is not linked to a syllabus chapter.');
        }

        $summary = $this->chapterSummary->forEnrollment($enrollment);
        $allowedIds = collect($summary['chapters'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (! in_array((int) $chapter->syllabus_chapter_id, $allowedIds, true)) {
            throw new InvalidArgumentException('This chapter is not on your study plan.');
        }
    }

    public function startOrResume(User $user, TextbookChapter $chapter): StudentConceptPathProgress
    {
        $this->assertStudentCanAccess($user, $chapter);

        $enrollment = $user->student?->currentEnrollment();
        $cards = $this->approvedCards($chapter);
        $total = count($cards);

        $progress = StudentConceptPathProgress::query()->firstOrNew([
            'user_id' => $user->id,
            'textbook_chapter_id' => $chapter->id,
        ]);

        $progress->student_enrollment_id = $enrollment?->id;
        $progress->syllabus_chapter_id = $chapter->syllabus_chapter_id;
        $progress->cards_total = $total;
        $progress->last_activity_at = now();

        if (! $progress->exists) {
            $progress->status = StudentConceptPathProgress::STATUS_IN_PROGRESS;
            $progress->cards_completed = 0;
            $progress->current_card_index = 0;
            $progress->events = [];
            $progress->started_at = now();
        } elseif ($progress->status !== StudentConceptPathProgress::STATUS_COMPLETED) {
            $progress->status = StudentConceptPathProgress::STATUS_IN_PROGRESS;
            $progress->started_at = $progress->started_at ?? now();
        }

        $progress->save();

        return $progress->fresh();
    }

    /**
     * @param  array{card_index?: int, card_step?: int, card_type?: string, correct?: bool|null, action?: string}  $event
     */
    public function recordCard(User $user, TextbookChapter $chapter, array $event): StudentConceptPathProgress
    {
        $progress = $this->startOrResume($user, $chapter);

        $cardIndex = max(0, (int) ($event['card_index'] ?? 0));
        $action = (string) ($event['action'] ?? 'advance');
        $events = is_array($progress->events) ? $progress->events : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'action' => $action,
            'card_index' => $cardIndex,
            'card_step' => (int) ($event['card_step'] ?? ($cardIndex + 1)),
            'card_type' => $event['card_type'] ?? null,
            'correct' => array_key_exists('correct', $event) ? $event['correct'] : null,
        ];

        // Keep the log bounded.
        if (count($events) > 200) {
            $events = array_slice($events, -200);
        }

        $completed = max((int) $progress->cards_completed, $cardIndex + ($action === 'advance' || $action === 'complete_card' ? 1 : 0));
        $completed = min($completed, (int) $progress->cards_total);

        $progress->events = $events;
        $progress->cards_completed = $completed;
        $progress->current_card_index = min($cardIndex, max(0, (int) $progress->cards_total - 1));
        $progress->last_activity_at = now();

        if ($progress->status !== StudentConceptPathProgress::STATUS_COMPLETED) {
            $progress->status = StudentConceptPathProgress::STATUS_IN_PROGRESS;
        }

        $progress->save();

        return $progress->fresh();
    }

    public function complete(User $user, TextbookChapter $chapter): StudentConceptPathProgress
    {
        $progress = $this->startOrResume($user, $chapter);
        $events = is_array($progress->events) ? $progress->events : [];
        $events[] = [
            'at' => now()->toIso8601String(),
            'action' => 'finish',
            'card_index' => (int) $progress->current_card_index,
            'card_step' => null,
            'card_type' => null,
            'correct' => null,
        ];

        $progress->events = $events;
        $progress->cards_completed = (int) $progress->cards_total;
        $progress->status = StudentConceptPathProgress::STATUS_COMPLETED;
        $progress->completed_at = now();
        $progress->last_activity_at = now();
        $progress->save();

        return $progress->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function playCards(TextbookChapter $chapter): array
    {
        $cards = $this->approvedCards($chapter);
        $cards = $this->conceptPath->withDiagramUrls($cards);

        return array_values(array_map(function (array $card, int $index) {
            $card['step'] = $index + 1;

            return $card;
        }, $cards, array_keys($cards)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvedCards(TextbookChapter $chapter): array
    {
        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];

        return array_values(array_filter(
            $cards,
            fn ($card) => is_array($card) && ($card['approved'] ?? true),
        ));
    }
}
