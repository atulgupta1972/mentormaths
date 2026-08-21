<?php

namespace App\Services;

use App\Models\ChapterHead;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\TextbookChapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;

class SyllabusImportService
{
    public function import(UploadedFile $file, SyllabusVersion $version): int
    {
        $rows = $this->parseFileToPreviewRows($file);

        if ($rows->isEmpty()) {
            return 0;
        }

        $this->syncRows($version, $rows->all(), replaceExisting: true);

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
            $data = $this->interpretChapterExtraColumn($this->mapRow($headers, $row));

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

            $chapterHead = $this->resolveChapterHeadFromRow($data, createIfMissing: false);

            $previewRows->push([
                'id' => null,
                'chapter_id' => null,
                'chapter_number' => $currentChapter->chapter_number,
                'chapter_name' => $currentChapter->name,
                'chapter_head_id' => $chapterHead['id'] ?? '',
                'chapter_head_name' => $chapterHead['name'] ?? '',
                'topic_name' => $topicName,
                'learning_outcomes' => $data['learning_outcomes'],
                'difficulty' => $data['difficulty'],
                'planned_periods' => $this->parsePeriods($data['planned_periods']) ?? '',
                'remarks' => $this->combineRemarks($data['remarks'], $data['ncert_chapter']),
            ]);
        }

        return $previewRows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{kept_content_chapters: list<string>}
     */
    public function syncRows(SyllabusVersion $version, array $rows, bool $replaceExisting = false): array
    {
        $keptContentChapters = [];

        DB::transaction(function () use ($version, $rows, $replaceExisting, &$keptContentChapters) {
            $protectedChapterIds = $this->protectedChapterIds($version);

            if ($replaceExisting) {
                $deletableIds = $version->chapters()->pluck('id')->diff($protectedChapterIds);
                SyllabusTopic::query()
                    ->whereIn('syllabus_chapter_id', $deletableIds)
                    ->delete();
                $version->chapters()->whereIn('id', $deletableIds)->delete();
            }

            $keptTopicIds = [];
            $chapterSort = 0;
            $chapterCache = [];
            $seenChapterIds = [];

            foreach ($rows as $index => $row) {
                if (trim((string) ($row['topic_name'] ?? '')) === '' && trim((string) ($row['chapter_name'] ?? '')) === '') {
                    continue;
                }

                $chapterSort++;
                $chapter = $this->resolveChapter($version, $row, $chapterSort, $chapterCache, createHeadsIfMissing: true);
                $seenChapterIds[(int) $chapter->id] = true;

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
            $protectedTopicIds = SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $protectedChapterIds)
                ->pluck('id');

            SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $chapterIds)
                ->whereNotIn('id', $keptTopicIds)
                ->whereNotIn('id', $protectedTopicIds)
                ->delete();

            $omittedProtected = $protectedChapterIds
                ->reject(fn ($id) => isset($seenChapterIds[(int) $id]))
                ->values();

            if ($omittedProtected->isNotEmpty()) {
                $keptContentChapters = SyllabusChapter::query()
                    ->whereIn('id', $omittedProtected)
                    ->get()
                    ->map(fn (SyllabusChapter $chapter) => trim($chapter->chapter_number.' '.$chapter->name))
                    ->all();
            }

            $version->chapters()
                ->whereDoesntHave('topics')
                ->whereNotIn('id', $protectedChapterIds)
                ->delete();

            $hasTopics = SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $version->chapters()->pluck('id'))
                ->exists();

            $version->update([
                'status' => $hasTopics
                    ? SyllabusVersion::STATUS_PUBLISHED
                    : SyllabusVersion::STATUS_DRAFT,
            ]);
        });

        return ['kept_content_chapters' => $keptContentChapters];
    }

    /**
     * @param  array{
     *     chapter_id?: int|null,
     *     chapter_number?: string|null,
     *     chapter_name?: string|null,
     *     chapter_head_id?: int|null
     * }  $chapterData
     * @param  array{
     *     topic_name: string,
     *     learning_outcomes?: string|null,
     *     difficulty?: string|null,
     *     planned_periods?: mixed,
     *     remarks?: string|null
     * }  $topicData
     */
    public function addTopic(SyllabusVersion $version, array $chapterData, array $topicData): SyllabusTopic
    {
        return DB::transaction(function () use ($version, $chapterData, $topicData) {
            $chapterCache = [];
            $chapterSort = ($version->chapters()->max('sort_order') ?? 0) + 1;

            $chapter = $this->resolveChapter($version, [
                'chapter_id' => $chapterData['chapter_id'] ?? null,
                'chapter_number' => $chapterData['chapter_number'] ?? '',
                'chapter_name' => $chapterData['chapter_name'] ?? '',
                'chapter_head_id' => $chapterData['chapter_head_id'] ?? null,
            ], $chapterSort, $chapterCache);

            $topicSort = SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $version->chapters()->pluck('id'))
                ->max('sort_order') ?? 0;

            $topic = SyllabusTopic::create([
                'syllabus_chapter_id' => $chapter->id,
                'name' => trim($topicData['topic_name']),
                'learning_outcomes' => $topicData['learning_outcomes'] ?? null,
                'difficulty' => $topicData['difficulty'] ?? null,
                'planned_periods' => $this->parsePeriods($topicData['planned_periods'] ?? null),
                'remarks' => $topicData['remarks'] ?? null,
                'sort_order' => $topicSort + 1,
            ]);

            $version->update(['status' => SyllabusVersion::STATUS_PUBLISHED]);

            return $topic;
        });
    }

    /**
     * Remove chapters that have no uploaded book MCQs or question-bank items.
     *
     * @return array{kept_content_chapters: list<string>}
     */
    public function clearAllRows(SyllabusVersion $version): array
    {
        $keptContentChapters = [];

        DB::transaction(function () use ($version, &$keptContentChapters) {
            $protectedChapterIds = $this->protectedChapterIds($version);
            $deletableIds = $version->chapters()->pluck('id')->diff($protectedChapterIds);

            SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $deletableIds)
                ->delete();
            $version->chapters()->whereIn('id', $deletableIds)->delete();

            if ($protectedChapterIds->isNotEmpty()) {
                $keptContentChapters = SyllabusChapter::query()
                    ->whereIn('id', $protectedChapterIds)
                    ->get()
                    ->map(fn (SyllabusChapter $chapter) => trim($chapter->chapter_number.' '.$chapter->name))
                    ->all();
            }

            $hasTopics = SyllabusTopic::query()
                ->whereIn('syllabus_chapter_id', $version->chapters()->pluck('id'))
                ->exists();

            $version->update([
                'status' => $hasTopics
                    ? SyllabusVersion::STATUS_PUBLISHED
                    : SyllabusVersion::STATUS_DRAFT,
            ]);
        });

        return ['kept_content_chapters' => $keptContentChapters];
    }

    /**
     * @return array{
     *     unrecognized: list<string>,
     *     mapped: list<string>,
     *     missing: list<string>
     * }
     */
    public function describeFileHeaders(UploadedFile $file): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP zip extension is required to read Excel (.xlsx) files.');
        }

        $spreadsheet = IOFactory::load($file->getRealPath());
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

        if ($rows === []) {
            return ['unrecognized' => [], 'mapped' => [], 'missing' => ['Sub-Topic']];
        }

        $headerIndex = $this->findHeaderRowIndex($rows);
        $headerRow = $rows[$headerIndex];
        $headers = $this->normalizeHeaders($headerRow);
        $mapped = array_values(array_unique(array_filter($headers)));
        $unrecognized = [];

        foreach ($headerRow as $index => $label) {
            $trimmed = trim((string) $label);

            if ($trimmed === '') {
                continue;
            }

            if (($headers[$index] ?? '') === '') {
                $unrecognized[] = $trimmed;
            }
        }

        $missing = [];

        if (! in_array('topic', $mapped, true)) {
            $missing[] = 'Sub-Topic';
        }

        if (! in_array('chapter_name', $mapped, true) && ! in_array('chapter_number', $mapped, true)) {
            $missing[] = 'Main Topic (Chapter) or Chapter No.';
        }

        return [
            'unrecognized' => $unrecognized,
            'mapped' => $mapped,
            'missing' => $missing,
        ];
    }

    public function flattenToRows(SyllabusVersion $version): Collection
    {
        $chapterColumns = [
            'id',
            'syllabus_version_id',
            'chapter_head_id',
            'chapter_number',
            'name',
            'sort_order',
            'created_at',
            'updated_at',
        ];

        if (Schema::hasColumn('syllabus_chapters', 'ncert_verified')) {
            $chapterColumns[] = 'ncert_verified';
        }

        $loadTextbookChapters = Schema::hasTable('textbook_chapters');

        try {
            $relations = [
                'chapters' => fn ($q) => $q->select($chapterColumns),
                'chapters.topics' => fn ($q) => $q->withCount('questions'),
                'chapters.chapterHead',
            ];

            if ($loadTextbookChapters) {
                $relations[] = 'chapters.textbookChapters.textbook';
            }

            // Mixed string/closure relation lists: load in two steps for clarity.
            $version->load([
                'chapters' => fn ($q) => $q->select($chapterColumns),
                'chapters.topics' => fn ($q) => $q->withCount('questions'),
                'chapters.chapterHead',
            ]);

            if ($loadTextbookChapters) {
                $version->load('chapters.textbookChapters.textbook');
            }
        } catch (\Throwable $e) {
            report($e);
            $version->unsetRelation('chapters');
            $version->load([
                'chapters' => fn ($q) => $q->select($chapterColumns),
                'chapters.topics' => fn ($q) => $q->withCount('questions'),
                'chapters.chapterHead',
            ]);
        }

        return $version->chapters->flatMap(function (SyllabusChapter $chapter) {
            $books = [];
            if ($chapter->relationLoaded('textbookChapters')) {
                $books = $chapter->textbookChapters
                    ->map(fn (TextbookChapter $bookChapter) => $bookChapter->textbook?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
            }
            $hasQuestions = $chapter->topics->contains(fn (SyllabusTopic $topic) => ($topic->questions_count ?? 0) > 0);

            return $chapter->topics->map(fn (SyllabusTopic $topic) => [
                'id' => $topic->id,
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'chapter_name' => $chapter->name,
                'chapter_head_id' => $chapter->chapter_head_id,
                'chapter_head_name' => $chapter->chapterHead?->name ?? '',
                'ncert_verified' => (bool) ($chapter->getAttributes()['ncert_verified'] ?? false),
                'has_uploaded_content' => $books !== [] || $hasQuestions,
                'content_books' => $books,
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
            'chapter_head_name' => '',
            'ncert_chapter' => '',
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
        $normalizedLabels = array_map(
            fn ($label) => $this->normalizeLabel((string) $label),
            $headerRow,
        );

        // Class 4 revised sheets use "Main Topic (Chapter)" for mentor heads (Geometry)
        // and a separate "CHAPTER NAME" column for NCERT unit titles (Shapes Around Us).
        // Class 5 sheets keep Main Topic as the chapter name and use "chapter" for heads.
        $hasDedicatedChapterNameColumn = in_array('chapter name', $normalizedLabels, true);

        $aliases = [
            'chapter_number' => ['chapter no.', 'chapter no', 'chapter number'],
            'chapter_name' => $hasDedicatedChapterNameColumn
                ? ['chapter name']
                : ['main topic', 'main topic (chapter)', 'chapter name'],
            'chapter_head_name' => $hasDedicatedChapterNameColumn
                ? ['main topic', 'main topic (chapter)', 'chapter head', 'mentor head', 'head']
                : ['chapter head', 'mentor head', 'head'],
            'ncert_chapter' => ['ncert chapter', 'textbook chapter', 'ncert unit', 'unit title', 'chapter'],
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

        if (preg_match('/(\d+)/', (string) $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * The optional "chapter" column is used either for NCERT unit titles (Class 4)
     * or mentor chapter-head names (Class 5). Match against chapter heads when possible.
     *
     * @param  array<string, string>  $data
     * @return array<string, string>
     */
    private function interpretChapterExtraColumn(array $data): array
    {
        if ($data['chapter_head_name'] !== '') {
            return $data;
        }

        $extra = trim($data['ncert_chapter']);

        if ($extra === '') {
            return $data;
        }

        $head = ChapterHead::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($extra)])
            ->first();

        if ($head) {
            $data['chapter_head_name'] = $head->name;
            $data['ncert_chapter'] = '';
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{id: int|null, name: string}|null
     */
    private function resolveChapterHeadFromRow(array $row, bool $createIfMissing = false): ?array
    {
        if (! empty($row['chapter_head_id'])) {
            $head = ChapterHead::query()->find($row['chapter_head_id']);

            return $head ? ['id' => $head->id, 'name' => $head->name] : null;
        }

        $name = trim((string) ($row['chapter_head_name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $head = ChapterHead::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        if ($head) {
            return ['id' => $head->id, 'name' => $head->name];
        }

        if (! $createIfMissing) {
            return ['id' => null, 'name' => $name];
        }

        $head = ChapterHead::create([
            'name' => $name,
            'sort_order' => ((int) ChapterHead::query()->max('sort_order')) + 1,
        ]);

        return ['id' => $head->id, 'name' => $head->name];
    }

    private function chapterHeadIdFromRow(array $row, bool $createIfMissing = false): ?int
    {
        $resolved = $this->resolveChapterHeadFromRow($row, $createIfMissing);

        if ($resolved === null) {
            return null;
        }

        return $resolved['id'];
    }

    private function combineRemarks(string $remarks, string $ncertChapter): string
    {
        $remarks = trim($remarks);
        $ncertChapter = trim($ncertChapter);

        if ($ncertChapter === '') {
            return $remarks;
        }

        $ncertLine = str_starts_with(strtolower($ncertChapter), 'ncert')
            ? $ncertChapter
            : "NCERT: {$ncertChapter}";

        return $remarks !== '' ? "{$ncertLine} · {$remarks}" : $ncertLine;
    }

    private function nullableIntId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, SyllabusChapter>  $chapterCache
     * @param  array<string, mixed>  $row
     */
    private function resolveChapter(SyllabusVersion $version, array $row, int $sortOrder, array &$chapterCache, bool $createHeadsIfMissing = false): SyllabusChapter
    {
        $chapterHeadId = $this->chapterHeadIdFromRow($row, $createHeadsIfMissing);

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
                'chapter_head_id' => $chapterHeadId,
                'ncert_verified' => $this->rowIsNcertVerified($row),
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

        $chapter = $key === '|'
            ? null
            : SyllabusChapter::query()
                ->where('syllabus_version_id', $version->id)
                ->get()
                ->first(fn (SyllabusChapter $existing) => $this->chapterKey(
                    (string) $existing->chapter_number,
                    (string) $existing->name,
                ) === $key);

        if ($chapter) {
            $chapter->update([
                'chapter_number' => $number ?: $chapter->chapter_number,
                'name' => $name ?: $chapter->name,
                'sort_order' => $sortOrder,
                'chapter_head_id' => $chapterHeadId ?? $chapter->chapter_head_id,
                'ncert_verified' => $this->rowIsNcertVerified($row),
            ]);
        } else {
            $chapter = SyllabusChapter::create([
                'syllabus_version_id' => $version->id,
                'chapter_head_id' => $chapterHeadId,
                'chapter_number' => $number ?: (string) $sortOrder,
                'name' => $name ?: 'Chapter '.$sortOrder,
                'sort_order' => $sortOrder,
                'ncert_verified' => $this->rowIsNcertVerified($row),
            ]);
        }

        $chapterCache[$key] = $chapter;
        $chapterCache['id:'.$chapter->id] = $chapter;

        return $chapter;
    }

    private function chapterKey(string $number, string $name): string
    {
        if (trim($number) === '' && trim($name) === '') {
            return '|';
        }

        return SyllabusChapter::headingKey($number, $name);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowIsNcertVerified(array $row): bool
    {
        return filter_var($row['ncert_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Chapters that have book MCQs or question-bank items — never auto-delete.
     *
     * @return Collection<int, int>
     */
    private function protectedChapterIds(SyllabusVersion $version): Collection
    {
        $chapterIds = $version->chapters()->pluck('id');

        if ($chapterIds->isEmpty()) {
            return collect();
        }

        $fromBooks = TextbookChapter::query()
            ->whereIn('syllabus_chapter_id', $chapterIds)
            ->pluck('syllabus_chapter_id');

        $fromQuestions = SyllabusTopic::query()
            ->whereIn('syllabus_chapter_id', $chapterIds)
            ->whereHas('questions')
            ->pluck('syllabus_chapter_id');

        return $fromBooks->merge($fromQuestions)->unique()->values();
    }
}
