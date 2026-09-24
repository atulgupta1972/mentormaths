<?php

namespace App\Services;

use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PracticeSetSplitService
{
    public const DEFAULT_BATCH_SIZE = TextbookSetCodeService::MCQ_BATCH_SIZE;

    public function __construct(
        private ClassCoverageService $coverageService,
    ) {}

    /**
     * @return list<array{part: int, count: int, set_code: string, from: int, to: int}>
     */
    public function buildPlan(int $questionCount, string $setCode, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        if ($questionCount <= 0) {
            return [];
        }

        $batchSize = max(1, $batchSize);
        $totalParts = (int) ceil($questionCount / $batchSize);
        $base = $this->baseCode($setCode);
        $plan = [];
        $from = 1;

        for ($part = 1; $part <= $totalParts; $part++) {
            $remaining = $questionCount - ($from - 1);
            $count = min($batchSize, $remaining);
            $plan[] = [
                'part' => $part,
                'count' => $count,
                'set_code' => $totalParts <= 1 ? $setCode : $base.$part,
                'from' => $from,
                'to' => $from + $count - 1,
            ];
            $from += $count;
        }

        return $plan;
    }

    public function baseCode(string $setCode): string
    {
        $trimmed = trim($setCode);
        if ($trimmed === '') {
            return 'SET';
        }

        $base = preg_replace('/\d+$/', '', $trimmed) ?? $trimmed;

        return $base !== '' ? $base : $trimmed;
    }

    /**
     * @param  list<int>  $sizes  e.g. [10, 10] or [12, 8]
     * @return list<array{part: int, count: int, set_code: string, from: int, to: int}>
     */
    public function buildPlanFromSizes(int $questionCount, string $setCode, array $sizes): array
    {
        $sizes = array_values(array_map('intval', $sizes));
        $sizes = array_values(array_filter($sizes, fn (int $n) => $n > 0));

        if ($sizes === []) {
            throw new InvalidArgumentException('Enter at least two part sizes, e.g. 10+10 or 12+8.');
        }

        if (count($sizes) < 2) {
            throw new InvalidArgumentException('Need at least two parts to split a sheet.');
        }

        if (array_sum($sizes) !== $questionCount) {
            throw new InvalidArgumentException(
                'Part sizes ('.implode('+', $sizes).") must add up to {$questionCount} sums."
            );
        }

        $base = $this->baseCode($setCode);
        $plan = [];
        $from = 1;

        foreach ($sizes as $index => $count) {
            $part = $index + 1;
            $plan[] = [
                'part' => $part,
                'count' => $count,
                'set_code' => $base.$part,
                'from' => $from,
                'to' => $from + $count - 1,
            ];
            $from += $count;
        }

        return $plan;
    }

    /**
     * Parse "10+10" / "12 + 8" / "10,10" into positive ints.
     *
     * @return list<int>
     */
    public function parseSizesExpression(string $expression): array
    {
        $parts = preg_split('/[+\s,;]+/', trim($expression)) ?: [];
        $sizes = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '' || ! ctype_digit($part)) {
                continue;
            }
            $n = (int) $part;
            if ($n > 0) {
                $sizes[] = $n;
            }
        }

        return $sizes;
    }

    public function canSplit(Worksheet $worksheet, int $batchSize = self::DEFAULT_BATCH_SIZE): bool
    {
        $count = $worksheet->questions_count ?? $worksheet->questions()->count();

        return $count > max(1, $batchSize);
    }

    /**
     * Worksheets that already use codes this set would need when divided
     * (any batch size from 5–50), so the UI can rename/delete them in place.
     *
     * @return list<array{id: int, set_code: string, title: string, questions_count: int}>
     */
    public function relatedSetsForSplitUi(Worksheet $worksheet): array
    {
        $questionCount = (int) ($worksheet->questions_count ?? $worksheet->questions()->count());
        if ($questionCount <= 5) {
            return [];
        }

        $base = $this->baseCode((string) $worksheet->set_code);
        $maxParts = (int) ceil($questionCount / 5);
        $codes = [];
        for ($part = 1; $part <= $maxParts; $part++) {
            $codes[] = $base.$part;
        }

        return Worksheet::query()
            ->whereIn('set_code', $codes)
            ->where('id', '!=', $worksheet->id)
            ->withCount('questions')
            ->orderBy('set_code')
            ->get(['id', 'set_code', 'title'])
            ->map(fn (Worksheet $row) => [
                'id' => $row->id,
                'set_code' => (string) $row->set_code,
                'title' => (string) $row->title,
                'questions_count' => (int) $row->questions_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Split using an explicit size list (preferred for written sheets: 10+10, 12+8).
     *
     * @param  list<int>  $sizes
     * @return array{kept: Worksheet, created: list<Worksheet>, plan: list<array{part: int, count: int, set_code: string, from: int, to: int}>}
     */
    public function splitWithSizes(Worksheet $worksheet, User $actor, array $sizes): array
    {
        $ordered = $worksheet->questions()
            ->orderBy('worksheet_question.sort_order')
            ->orderBy('questions.id')
            ->get(['questions.id']);

        $questionCount = $ordered->count();
        $plan = $this->buildPlanFromSizes($questionCount, (string) $worksheet->set_code, $sizes);

        return $this->applyPlan($worksheet, $actor, $ordered, $plan);
    }

    /**
     * Split a large practice set into ordered parts of up to $batchSize questions each.
     * The original worksheet keeps part 1; additional worksheets are created for the rest.
     *
     * @return array{kept: Worksheet, created: list<Worksheet>, plan: list<array{part: int, count: int, set_code: string, from: int, to: int}>}
     */
    public function split(Worksheet $worksheet, User $actor, int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $batchSize = max(1, min(50, $batchSize));

        $ordered = $worksheet->questions()
            ->orderBy('worksheet_question.sort_order')
            ->orderBy('questions.id')
            ->get(['questions.id']);

        $questionCount = $ordered->count();
        if ($questionCount <= $batchSize) {
            throw new InvalidArgumentException(
                "This set has {$questionCount} question(s). Increase the set size or lower the batch size to divide it."
            );
        }

        $plan = $this->buildPlan($questionCount, (string) $worksheet->set_code, $batchSize);

        return $this->applyPlan($worksheet, $actor, $ordered, $plan);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Question>|mixed  $ordered
     * @param  list<array{part: int, count: int, set_code: string, from: int, to: int}>  $plan
     * @return array{kept: Worksheet, created: list<Worksheet>, plan: list<array{part: int, count: int, set_code: string, from: int, to: int}>}
     */
    private function applyPlan(Worksheet $worksheet, User $actor, $ordered, array $plan): array
    {
        $codes = array_column($plan, 'set_code');

        $conflicts = Worksheet::query()
            ->whereIn('set_code', $codes)
            ->where('id', '!=', $worksheet->id)
            ->pluck('set_code')
            ->all();

        if ($conflicts !== []) {
            throw new InvalidArgumentException(
                'Cannot divide: set code(s) already exist — '.implode(', ', $conflicts).'. Rename or delete those sets first.'
            );
        }

        $result = DB::transaction(function () use ($worksheet, $actor, $ordered, $plan) {
            $baseTitle = (string) $worksheet->title;
            $chunks = [];
            $offset = 0;
            foreach ($plan as $row) {
                $chunks[] = $ordered->slice($offset, $row['count'])->values();
                $offset += $row['count'];
            }

            $firstChunk = $chunks[0];
            $keepIds = $firstChunk->pluck('id')->all();

            $worksheet->questions()->detach();
            foreach ($keepIds as $index => $questionId) {
                $worksheet->questions()->attach($questionId, ['sort_order' => $index + 1]);
            }

            $worksheetUpdates = [
                'set_code' => $plan[0]['set_code'],
                'title' => $this->titledPart($baseTitle, 1, count($plan)),
            ];

            if ($worksheet->isWritten()) {
                $worksheetUpdates['written_status'] = WrittenSheetStatus::PENDING_REVIEW;
                $worksheetUpdates['written_verified_at'] = null;
                $worksheetUpdates['written_verified_by'] = null;
            }

            $worksheet->update($worksheetUpdates);

            $created = [];
            $nextSetNumber = $this->nextSetNumberAfter($worksheet);

            for ($i = 1; $i < count($plan); $i++) {
                $part = $plan[$i];
                $chunk = $chunks[$i];
                $setNumber = $worksheet->syllabus_topic_id
                    ? $nextSetNumber++
                    : (int) $worksheet->set_number + $i;

                $sibling = Worksheet::create([
                    'title' => $this->titledPart($baseTitle, $i + 1, count($plan)),
                    'set_number' => $setNumber,
                    'set_code' => $part['set_code'],
                    'tier' => $worksheet->tier,
                    'scope' => $worksheet->scope ?? PracticeSetScope::CHAPTER,
                    'syllabus_topic_id' => $worksheet->syllabus_topic_id,
                    'syllabus_chapter_id' => $worksheet->syllabus_chapter_id,
                    'status' => $worksheet->status,
                    'notes' => $worksheet->notes,
                    'created_by' => $actor->id,
                    'purpose' => $worksheet->purpose,
                    'delivery_mode' => $worksheet->delivery_mode ?? WorksheetDeliveryMode::ONLINE,
                    'written_status' => $worksheet->isWritten() ? WrittenSheetStatus::PENDING_REVIEW : null,
                    'written_pdf_path' => null,
                    'written_verified_at' => null,
                    'written_verified_by' => null,
                ]);

                foreach ($chunk as $index => $question) {
                    $sibling->questions()->attach($question->id, ['sort_order' => $index + 1]);
                }

                $created[] = $sibling;
            }

            $allIds = array_merge([$worksheet->id], array_map(fn (Worksheet $w) => $w->id, $created));
            $this->syncTextbookChapterLinks($worksheet->id, $allIds);

            $worksheet->refresh()->loadCount('questions');

            return [
                'kept' => $worksheet,
                'created' => $created,
                'plan' => $plan,
            ];
        });

        $this->coverageService->assignNewWorksheetsDueToday($result['created'], $actor);

        return $result;
    }

    private function titledPart(string $title, int $part, int $totalParts): string
    {
        $clean = preg_replace('/\s*—\s*Part\s+\d+\s*$/u', '', $title) ?? $title;
        $clean = trim($clean);

        if ($totalParts <= 1) {
            return $clean;
        }

        return "{$clean} — Part {$part}";
    }

    private function nextSetNumberAfter(Worksheet $worksheet): int
    {
        if (! $worksheet->syllabus_topic_id) {
            return (int) $worksheet->set_number + 1;
        }

        $max = (int) Worksheet::query()
            ->where('syllabus_topic_id', $worksheet->syllabus_topic_id)
            ->max('set_number');

        return max($max + 1, (int) $worksheet->set_number + 1);
    }

    /**
     * @param  list<int>  $replacementIds
     */
    private function syncTextbookChapterLinks(int $originalId, array $replacementIds): void
    {
        $chapters = TextbookChapter::query()
            ->where(function ($q) use ($originalId) {
                $q->where('mcq_worksheet_id', $originalId)
                    ->orWhere('fill_blank_worksheet_id', $originalId)
                    ->orWhere('written_worksheet_id', $originalId);
            })
            ->get();

        $extra = TextbookChapter::query()
            ->where(function ($q) {
                $q->whereNotNull('mcq_worksheet_ids')
                    ->orWhereNotNull('fill_blank_worksheet_ids')
                    ->orWhereNotNull('written_worksheet_ids');
            })
            ->when($chapters->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $chapters->pluck('id')))
            ->get()
            ->filter(function (TextbookChapter $chapter) use ($originalId) {
                return in_array($originalId, $chapter->mcqWorksheetIds(), true)
                    || in_array($originalId, $chapter->fillBlankWorksheetIds(), true)
                    || in_array($originalId, $chapter->writtenWorksheetIds(), true);
            });

        foreach ($chapters->merge($extra) as $chapter) {
            $updates = [];

            foreach ([
                ['mcq_worksheet_id', 'mcqWorksheetIds', 'mcq_worksheet_ids'],
                ['fill_blank_worksheet_id', 'fillBlankWorksheetIds', 'fill_blank_worksheet_ids'],
                ['written_worksheet_id', 'writtenWorksheetIds', 'written_worksheet_ids'],
            ] as [$single, $getter, $list]) {
                $ids = $chapter->{$getter}();
                if (! in_array($originalId, $ids, true)) {
                    continue;
                }

                $pos = array_search($originalId, $ids, true);
                array_splice($ids, (int) $pos, 1, $replacementIds);
                $ids = array_values(array_unique(array_map('intval', $ids)));

                $updates[$list] = $ids;
                $updates[$single] = $ids[0] ?? null;
            }

            if ($updates !== []) {
                $chapter->update($updates);
            }
        }
    }
}
