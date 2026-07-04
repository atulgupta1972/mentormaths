<?php

namespace App\Services;

use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SyllabusImportService
{
    public function import(UploadedFile $file, SyllabusVersion $version): int
    {
        $rows = $this->parseFileToPreviewRows($file);

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->syncRows($version, $rows->all());

        return $rows->count();
    }

    /**
     * Parse an Excel syllabus file into editable row arrays without touching the database.
     */
    public function parseFileToPreviewRows(UploadedFile $file): Collection
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is required to read Excel (.xlsx) files.');
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if ($rows === []) {
            return collect();
        }

        $headerIndex = $this->findHeaderRowIndex($rows);
        $headers = $this->normalizeHeaders($rows[$headerIndex]);
        $dataRows = array_slice($rows, $headerIndex + 1);

        $previewRows = collect();
        $currentChapter = null;
        $chapterSort = 0;

        foreach ($dataRows as $row) {
            $data = $this->mapRow($headers, $row);

            if ($data['topic'] === '' && $data['chapter_name'] === '') {
                continue;
            }

            if ($this->shouldCreateChapter($data, $currentChapter)) {
                $chapterSort++;
                $currentChapter = new SyllabusChapter([
                    'chapter_number' => $this->cleanChapterNumber($data['chapter_number']) ?: (string) $chapterSort,
                    'name' => $data['chapter_name'] ?: $data['topic'],
                ]);
            }

            if (! $currentChapter) {
                continue;
            }

            $topicName = $data['topic'] ?: $currentChapter->name;

            if ($topicName === '') {
                continue;
            }

            $previewRows->push([
                'id' => null,
                'chapter_id' => null,
                'chapter_number' => $currentChapter->chapter_number,
                'chapter_name' => $currentChapter->name,
                'chapter_head_id' => '',
                'chapter_head_name' => '',
                'topic_name' => $topicName,
                'learning_outcomes' => $data['learning_outcomes'],
                'difficulty' => $data['difficulty'],
                'planned_periods' => $this->parsePeriods($data['planned_periods']) ?? '',
                'remarks' => $data['remarks'],
            ]);
        }

        return $previewRows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncRows(SyllabusVersion $version, array $rows): void
    {
        DB::transaction(function () use ($version, $rows) {
            $keptTopicIds = [];
            $chapterSort = 0;
            $chapterCache = [];

            foreach ($rows as $index => $row) {
                if (trim((string) ($row['topic_name'] ?? '')) === '' && trim((string) ($row['chapter_name'] ?? '')) === '') {
                    continue;
                }

                $chapterSort++;
                $chapter = $this->resolveChapter($version, $row, $chapterSort, $chapterCache);

                $topic = isset($row['id']) && $row['id']
                    ? SyllabusTopic::query()
                        ->whereHas('chapter', fn ($q) => $q->where('syllabus_version_id', $version->id))
                        ->findOrFail($row['id'])
                    : new SyllabusTopic(['syllabus_chapter_id' => $chapter->id]);

                $topic->fill([
                    'syllabus_chapter_id' => $chapter->id,
                    'name' => trim((string) ($row['topic_name'] ?? '')) ?: trim((string) ($row['chapter_name'] ?? '')),
                    'learning_outcomes' => $row['learning_outcomes'] ?? null,
                    'difficulty' => $row['difficulty'] ?? null,
                    'planned_periods' => $this->parsePeriods($row['planned_periods'] ?? null),
                    'remarks' => $row['remarks'] ?? null,
                    'sort_order' => $index + 1,
                ]);
                $topic->save();
                $keptTopicIds[] = $topic->id;
            }

            $chapterIds = $version->chapters()->pluck('id');
            SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $chapterIds)
                ->whereNotIn('id', $keptTopicIds)
                ->delete();

            $version->chapters()
                ->whereDoesntHave('topics')
                ->delete();

            $version->update(['status' => SyllabusVersion::STATUS_PUBLISHED]);
        });
    }

    public function flattenToRows(SyllabusVersion $version): Collection
    {
        $version->load(['chapters.topics', 'chapters.chapterHead']);

        return $version->chapters->flatMap(function (SyllabusChapter $chapter) {
            return $chapter->topics->map(fn (SyllabusTopic $topic) => [
                'id' => $topic->id,
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'chapter_name' => $chapter->name,
                'chapter_head_id' => $chapter->chapter_head_id,
                'chapter_head_name' => $chapter->chapterHead?->name ?? '',
                'topic_name' => $topic->name,
                'learning_outcomes' => $topic->learning_outcomes ?? '',
                'difficulty' => $topic->difficulty ?? '',
                'planned_periods' => $topic->planned_periods ?? '',
                'remarks' => $topic->remarks ?? '',
            ]);
        })->values();
    }

    /**
     * @param  list<mixed>  $row
     * @return array<string, string>
     */
    private function mapRow(array $headers, array $row): array
    {
        $mapped = [
            'chapter_number' => '',
            'chapter_name' => '',
            'topic' => '',
            'learning_outcomes' => '',
            'difficulty' => '',
            'planned_periods' => '',
            'remarks' => '',
        ];

        foreach ($headers as $index => $key) {
            if ($key === '' || ! isset($row[$index])) {
                continue;
            }

            $value = trim((string) $row[$index]);

            if (isset($mapped[$key])) {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function findHeaderRowIndex(array $rows): int
    {
        foreach ($rows as $index => $row) {
            $normalized = array_map(
                fn ($cell) => $this->normalizeLabel((string) $cell),
                $row
            );

            $hasSubTopic = in_array('sub-topic', $normalized, true) || in_array('sub topic', $normalized, true);
            $hasChapterNo = in_array('chapter no.', $normalized, true) || in_array('chapter no', $normalized, true);
            $hasMainTopic = in_array('main topic (chapter)', $normalized, true) || in_array('main topic', $normalized, true);

            if ($hasSubTopic || ($hasChapterNo && $hasMainTopic)) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @param  list<mixed>  $headerRow
     * @return array<int, string>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        $aliases = [
            'chapter_number' => ['chapter no.', 'chapter no', 'chapter number', 'chapter'],
            'chapter_name' => ['main topic', 'main topic (chapter)', 'chapter name', 'unit'],
            'topic' => ['sub-topic', 'sub topic', 'topic', 'subtopic'],
            'learning_outcomes' => ['key concepts', 'key concepts / learning outcomes', 'learning outcomes', 'concepts'],
            'difficulty' => ['difficulty level', 'difficulty'],
            'planned_periods' => ['approx. periods', 'approx periods', 'periods'],
            'remarks' => ['remarks', 'notes'],
        ];

        $headers = [];

        foreach ($headerRow as $index => $label) {
            $normalized = $this->normalizeLabel((string) $label);
            $headers[$index] = '';

            foreach ($aliases as $key => $options) {
                if (in_array($normalized, $options, true)) {
                    $headers[$index] = $key;
                    break;
                }
            }
        }

        return $headers;
    }

    private function normalizeLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = str_replace(['/', '\\'], ' / ', $label);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return $label;
    }

    private function shouldCreateChapter(array $data, ?SyllabusChapter $currentChapter): bool
    {
        if (! $currentChapter) {
            return true;
        }

        $chapterNumber = $this->cleanChapterNumber($data['chapter_number']);
        $currentNumber = $this->cleanChapterNumber($currentChapter->chapter_number);

        if ($chapterNumber !== '' && $chapterNumber !== $currentNumber) {
            return true;
        }

        if ($data['chapter_name'] !== '' && $data['chapter_name'] !== $currentChapter->name) {
            return true;
        }

        return false;
    }

    private function cleanChapterNumber(?string $value): string
    {
        return trim((string) $value);
    }

    private function parsePeriods(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) preg_replace('/\D/', '', (string) $value);
    }

    /**
     * @param  array<string, SyllabusChapter>  $chapterCache
     * @param  array<string, mixed>  $row
     */
    private function resolveChapter(SyllabusVersion $version, array $row, int $sortOrder, array &$chapterCache): SyllabusChapter
    {
        if (! empty($row['chapter_id'])) {
            $cacheKey = 'id:'.$row['chapter_id'];

            if (isset($chapterCache[$cacheKey])) {
                return $chapterCache[$cacheKey];
            }

            $chapter = SyllabusChapter::query()
                ->where('syllabus_version_id', $version->id)
                ->findOrFail($row['chapter_id']);

            $chapter->update([
                'chapter_number' => $this->cleanChapterNumber($row['chapter_number'] ?? '') ?: $chapter->chapter_number,
                'name' => trim((string) ($row['chapter_name'] ?? '')) ?: $chapter->name,
                'sort_order' => $sortOrder,
                'chapter_head_id' => ! empty($row['chapter_head_id']) ? (int) $row['chapter_head_id'] : null,
            ]);

            $chapterCache[$cacheKey] = $chapter;
            $chapterCache[$this->chapterKey($chapter->chapter_number, $chapter->name)] = $chapter;

            return $chapter;
        }

        $number = $this->cleanChapterNumber($row['chapter_number'] ?? '');
        $name = trim((string) ($row['chapter_name'] ?? ''));
        $key = $this->chapterKey($number, $name);

        if ($key !== '|' && isset($chapterCache[$key])) {
            return $chapterCache[$key];
        }

        $chapter = SyllabusChapter::create([
            'syllabus_version_id' => $version->id,
            'chapter_head_id' => $row['chapter_head_id'] ?? null,
            'chapter_number' => $number ?: (string) $sortOrder,
            'name' => $name ?: 'Chapter '.$sortOrder,
            'sort_order' => $sortOrder,
        ]);

        $chapterCache[$key] = $chapter;
        $chapterCache['id:'.$chapter->id] = $chapter;

        return $chapter;
    }

    private function chapterKey(string $number, string $name): string
    {
        return $number.'|'.$name;
    }
}
