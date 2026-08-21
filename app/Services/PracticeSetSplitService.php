<?php

namespace App\Services;

use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PracticeSetSplitService
{
    public const DEFAULT_BATCH_SIZE = TextbookSetCodeService::MCQ_BATCH_SIZE;

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

    public function canSplit(Worksheet $worksheet, int $batchSize = self::DEFAULT_BATCH_SIZE): bool
    {
        $count = $worksheet->questions_count ?? $worksheet->questions()->count();

        return $count > max(1, $batchSize);
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

        return DB::transaction(function () use ($worksheet, $actor, $ordered, $plan) {
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

            $worksheet->update([
                'set_code' => $plan[0]['set_code'],
                'title' => $this->titledPart($baseTitle, 1, count($plan)),
            ]);

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
                    'delivery_mode' => $worksheet->delivery_mode,
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
