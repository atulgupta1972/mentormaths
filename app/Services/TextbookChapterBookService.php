<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TextbookChapterBookService
{
    /**
     * @return list<array{id: int, name: string, code: string, label: string}>
     */
    public function textbooksForGrade(int $gradeLevelId): array
    {
        return Textbook::query()
            ->where('grade_level_id', $gradeLevelId)
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Textbook $book) => [
                'id' => $book->id,
                'name' => $book->name,
                'code' => $book->code,
                'label' => "{$book->name} ({$book->code})",
            ])
            ->values()
            ->all();
    }

    public function uploadPdf(TextbookChapter $chapter, UploadedFile $file, User $user, bool $uploaderContext = false): TextbookChapter
    {
        if ($uploaderContext) {
            $this->assertUploaderCanEditChapter($chapter, $user);
        }

        $chapter->loadMissing('textbook');
        $textbookId = (int) ($chapter->textbook_id ?? 0);

        if ($textbookId <= 0) {
            throw new \InvalidArgumentException('Chapter has no textbook linked.');
        }

        $directory = 'textbooks/'.$textbookId.'/chapters/'.$chapter->chapter_number;

        if ($chapter->pdf_path && Storage::disk('public')->exists($chapter->pdf_path)) {
            Storage::disk('public')->delete($chapter->pdf_path);
        }

        $pdfPath = $file->store($directory, 'public');
        $chapter->update(['pdf_path' => $pdfPath]);

        return $chapter->fresh(['textbook.gradeLevel']);
    }

    public function changeBook(
        TextbookChapter $chapter,
        User $user,
        ?int $textbookId = null,
        ?string $bookName = null,
        ?string $bookCode = null,
        bool $asAdmin = false,
    ): TextbookChapter {
        $chapter->loadMissing('textbook.gradeLevel');
        $gradeLevelId = (int) ($chapter->textbook?->grade_level_id ?? 0);

        if ($gradeLevelId <= 0) {
            throw new \InvalidArgumentException('Chapter class is missing.');
        }

        if (! $asAdmin) {
            $this->assertUploaderCanEditChapter($chapter, $user);
        }

        if ($textbookId) {
            $textbook = Textbook::query()->findOrFail($textbookId);

            if ((int) $textbook->grade_level_id !== $gradeLevelId) {
                throw new \InvalidArgumentException('Selected book is not for this class.');
            }
        } else {
            $name = trim((string) $bookName);
            $code = strtolower(trim((string) $bookCode));

            if ($name === '' || $code === '') {
                throw new \InvalidArgumentException('Enter book name and code, or pick an existing book.');
            }

            $textbook = Textbook::query()->firstOrCreate(
                [
                    'grade_level_id' => $gradeLevelId,
                    'code' => $code,
                ],
                [
                    'name' => $name,
                    'created_by' => $user->id,
                ],
            );

            if ($textbook->name !== $name) {
                $textbook->update(['name' => $name]);
            }
        }

        if ((int) $textbook->id === (int) $chapter->textbook_id) {
            return $chapter->fresh(['textbook.gradeLevel']);
        }

        $duplicate = TextbookChapter::query()
            ->where('textbook_id', $textbook->id)
            ->where('syllabus_chapter_id', $chapter->syllabus_chapter_id)
            ->whereKeyNot($chapter->id)
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException(
                'That book already has this syllabus chapter. Pick another book or ask admin to merge.',
            );
        }

        $chapter->update(['textbook_id' => $textbook->id]);

        return $chapter->fresh(['textbook.gradeLevel']);
    }

    /**
     * @return list<array{grade_level_id: int, grade_name: string, chapters: list<array{id: int, label: string}>}>
     */
    public function contentMoveTargets(SyllabusVersion $version): array
    {
        $chapters = SyllabusChapter::query()
            ->with([
                'syllabusVersion.board:id,code,name',
                'syllabusVersion.gradeLevel:id,name,sort_order',
            ])
            ->whereHas(
                'syllabusVersion',
                fn ($query) => $query
                    ->where('subject_id', $version->subject_id)
                    ->where('academic_year_id', $version->academic_year_id),
            )
            ->get()
            ->sortBy(function (SyllabusChapter $chapter) {
                $gradeSort = str_pad((string) ($chapter->syllabusVersion?->gradeLevel?->sort_order ?? 99), 2, '0', STR_PAD_LEFT);
                $board = $chapter->syllabusVersion?->board?->code ?? '';
                $number = str_pad((string) $chapter->numericChapterNumber(), 3, '0', STR_PAD_LEFT);

                return "{$gradeSort}|{$board}|{$number}";
            })
            ->values();

        $grades = [];

        foreach ($chapters as $chapter) {
            $grade = $chapter->syllabusVersion?->gradeLevel;

            if (! $grade) {
                continue;
            }

            $gradeId = (int) $grade->id;

            if (! isset($grades[$gradeId])) {
                $grades[$gradeId] = [
                    'grade_level_id' => $gradeId,
                    'grade_name' => $grade->name,
                    'chapters' => [],
                ];
            }

            $board = $chapter->syllabusVersion?->board?->code
                ?: $chapter->syllabusVersion?->board?->name
                ?: 'Board';

            $grades[$gradeId]['chapters'][] = [
                'id' => $chapter->id,
                'label' => "{$board} · Ch {$chapter->chapter_number} — {$chapter->name}",
            ];
        }

        return array_values($grades);
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function syllabusChaptersForRelink(TextbookChapter $chapter): array
    {
        $chapter->loadMissing('syllabusChapter.syllabusVersion');
        $version = $chapter->syllabusChapter?->syllabusVersion;

        if (! $version) {
            return [];
        }

        $currentId = (int) $chapter->syllabus_chapter_id;
        $options = [];

        foreach ($this->contentMoveTargets($version) as $grade) {
            foreach ($grade['chapters'] as $option) {
                if ((int) $option['id'] === $currentId) {
                    continue;
                }

                $options[] = [
                    'id' => $option['id'],
                    'label' => $grade['grade_name'].' · '.$option['label'],
                ];
            }
        }

        return $options;
    }

    public function changeSyllabusChapter(TextbookChapter $chapter, int $syllabusChapterId): TextbookChapter
    {
        $chapter->loadMissing(['textbook', 'syllabusChapter.syllabusVersion']);

        $target = SyllabusChapter::query()
            ->with('syllabusVersion.gradeLevel')
            ->findOrFail($syllabusChapterId);

        $subjectId = (int) ($chapter->syllabusChapter?->syllabusVersion?->subject_id ?? 0);
        $targetSubjectId = (int) ($target->syllabusVersion?->subject_id ?? 0);
        $targetGradeId = (int) ($target->syllabusVersion?->grade_level_id ?? 0);

        if ($subjectId > 0 && $targetSubjectId !== $subjectId) {
            throw new \InvalidArgumentException('Pick a syllabus chapter from the same subject.');
        }

        if ($targetGradeId <= 0) {
            throw new \InvalidArgumentException('That chapter has no class linked.');
        }

        if ((int) $target->id === (int) $chapter->syllabus_chapter_id) {
            return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.board']);
        }

        $textbook = $this->textbookForGrade($chapter->textbook, $targetGradeId);

        return DB::transaction(function () use ($chapter, $target, $textbook) {
            $oldChapterId = (int) $chapter->syllabus_chapter_id;
            $newTopic = $this->textbookTopic($target);
            $questionIds = $this->questionIdsToMove($chapter, $oldChapterId);

            if ($questionIds !== []) {
                Question::query()->whereIn('id', $questionIds)->update([
                    'syllabus_topic_id' => $newTopic->id,
                ]);
            }

            $worksheetIds = $this->worksheetIdsForChapter($chapter);

            if ($worksheetIds !== []) {
                Worksheet::query()->whereIn('id', $worksheetIds)->update([
                    'syllabus_chapter_id' => $target->id,
                ]);
            }

            $chapter->update([
                'textbook_id' => $textbook->id,
                'syllabus_chapter_id' => $target->id,
                'chapter_number' => $target->numericChapterNumber() ?: $chapter->chapter_number,
                'title' => $target->name,
            ]);

            return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.board']);
        });
    }

    public function moveSyllabusChapterContent(SyllabusChapter $source, SyllabusChapter $target): string
    {
        $source->loadMissing([
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'textbookChapters.textbook',
        ]);
        $target->loadMissing(['syllabusVersion.board', 'syllabusVersion.gradeLevel']);

        if ((int) $source->id === (int) $target->id) {
            throw new \InvalidArgumentException('Pick a different chapter to move the questions to.');
        }

        $sourceSubject = (int) ($source->syllabusVersion?->subject_id ?? 0);
        $targetSubject = (int) ($target->syllabusVersion?->subject_id ?? 0);

        if ($sourceSubject === 0 || $sourceSubject !== $targetSubject) {
            throw new \InvalidArgumentException('Pick a chapter from the same subject.');
        }

        return DB::transaction(function () use ($source, $target) {
            foreach ($source->textbookChapters as $bookChapter) {
                $this->changeSyllabusChapter($bookChapter, (int) $target->id);
            }

            $newTopic = $this->textbookTopic($target);
            $oldTopicIds = SyllabusTopic::query()
                ->where('syllabus_chapter_id', $source->id)
                ->pluck('id');

            if ($oldTopicIds->isNotEmpty()) {
                Question::query()->whereIn('syllabus_topic_id', $oldTopicIds)->update([
                    'syllabus_topic_id' => $newTopic->id,
                ]);
            }

            Worksheet::query()
                ->where('syllabus_chapter_id', $source->id)
                ->update(['syllabus_chapter_id' => $target->id]);

            return $this->chapterDestinationLabel($target);
        });
    }

    public function uploaderCanChangeBook(TextbookChapter $chapter, User $user): bool
    {
        try {
            $this->assertUploaderCanEditChapter($chapter, $user);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function hasStoredPdf(TextbookChapter $chapter): bool
    {
        return filled($chapter->pdf_path)
            && Storage::disk('public')->exists($chapter->pdf_path);
    }

    private function assertUploaderCanEditChapter(TextbookChapter $chapter, User $user): void
    {
        $task = ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->latest('id')
            ->first();

        if (! $task) {
            throw new \InvalidArgumentException('You are not assigned to this chapter.');
        }

        if ($task->isLockedForUploaderDelete()) {
            throw new \InvalidArgumentException(
                'This chapter is submitted or published. Ask admin to change the book.',
            );
        }
    }

    private function textbookForGrade(?Textbook $textbook, int $gradeLevelId): Textbook
    {
        if (! $textbook) {
            throw new \InvalidArgumentException('This chapter has no textbook linked.');
        }

        if ((int) $textbook->grade_level_id === $gradeLevelId) {
            return $textbook;
        }

        $moved = Textbook::query()->firstOrCreate(
            [
                'grade_level_id' => $gradeLevelId,
                'code' => $textbook->code,
            ],
            [
                'name' => $textbook->name,
                'is_active' => true,
                'created_by' => $textbook->created_by,
            ],
        );

        if ($moved->name !== $textbook->name) {
            $moved->update(['name' => $textbook->name]);
        }

        return $moved;
    }

    private function chapterDestinationLabel(SyllabusChapter $chapter): string
    {
        $grade = $chapter->syllabusVersion?->gradeLevel?->name ?? 'Class';
        $board = $chapter->syllabusVersion?->board?->code
            ?: $chapter->syllabusVersion?->board?->name
            ?: '';

        return trim("{$grade} {$board} · Ch {$chapter->chapter_number} — {$chapter->name}");
    }

    private function textbookTopic(SyllabusChapter $chapter): SyllabusTopic
    {
        return SyllabusTopic::query()->firstOrCreate(
            [
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Textbook',
            ],
            [
                'sort_order' => 900,
                'learning_outcomes' => 'Textbook examples and exercises',
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function worksheetIdsForChapter(TextbookChapter $chapter): array
    {
        return array_values(array_unique(array_filter([
            ...$chapter->mcqWorksheetIds(),
            (int) ($chapter->written_worksheet_id ?? 0),
            (int) ($chapter->fill_blank_worksheet_id ?? 0),
        ])));
    }

    /**
     * @return list<int>
     */
    private function questionIdsToMove(TextbookChapter $chapter, int $oldChapterId): array
    {
        $worksheetIds = $this->worksheetIdsForChapter($chapter);
        $fromWorksheets = [];

        if ($worksheetIds !== []) {
            $fromWorksheets = Worksheet::query()
                ->whereIn('id', $worksheetIds)
                ->with('questions:id')
                ->get()
                ->flatMap(fn (Worksheet $worksheet) => $worksheet->questions->pluck('id'))
                ->all();
        }

        $otherBooksRemain = TextbookChapter::query()
            ->where('syllabus_chapter_id', $oldChapterId)
            ->whereKeyNot($chapter->id)
            ->exists();

        if ($otherBooksRemain) {
            return array_values(array_unique(array_map('intval', $fromWorksheets)));
        }

        $oldTextbookTopicIds = SyllabusTopic::query()
            ->where('syllabus_chapter_id', $oldChapterId)
            ->where('name', 'Textbook')
            ->pluck('id');

        $fromTopic = $oldTextbookTopicIds->isEmpty()
            ? []
            : Question::query()->whereIn('syllabus_topic_id', $oldTextbookTopicIds)->pluck('id')->all();

        return array_values(array_unique(array_map('intval', [...$fromWorksheets, ...$fromTopic])));
    }
}
