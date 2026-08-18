<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
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
     * @return list<array{id: int, label: string}>
     */
    public function syllabusChaptersForRelink(TextbookChapter $chapter): array
    {
        $chapter->loadMissing(['textbook', 'syllabusChapter.syllabusVersion.board']);

        $gradeLevelId = (int) ($chapter->textbook?->grade_level_id ?? 0);
        $subjectId = (int) ($chapter->syllabusChapter?->syllabusVersion?->subject_id ?? 0);

        if ($gradeLevelId <= 0 || $subjectId <= 0) {
            return [];
        }

        return SyllabusChapter::query()
            ->with(['syllabusVersion.board:id,code,name'])
            ->whereHas(
                'syllabusVersion',
                fn ($query) => $query
                    ->where('grade_level_id', $gradeLevelId)
                    ->where('subject_id', $subjectId),
            )
            ->orderBy('sort_order')
            ->get()
            ->map(function (SyllabusChapter $syllabusChapter) {
                $board = $syllabusChapter->syllabusVersion?->board?->code
                    ?: $syllabusChapter->syllabusVersion?->board?->name
                    ?: 'Board';

                return [
                    'id' => $syllabusChapter->id,
                    'label' => "{$board} · Ch {$syllabusChapter->chapter_number} — {$syllabusChapter->name}",
                ];
            })
            ->values()
            ->all();
    }

    public function changeSyllabusChapter(TextbookChapter $chapter, int $syllabusChapterId): TextbookChapter
    {
        $chapter->loadMissing(['textbook', 'syllabusChapter.syllabusVersion']);

        $target = SyllabusChapter::query()
            ->with('syllabusVersion')
            ->findOrFail($syllabusChapterId);

        $gradeLevelId = (int) ($chapter->textbook?->grade_level_id ?? 0);
        $subjectId = (int) ($chapter->syllabusChapter?->syllabusVersion?->subject_id ?? 0);
        $targetGradeId = (int) ($target->syllabusVersion?->grade_level_id ?? 0);
        $targetSubjectId = (int) ($target->syllabusVersion?->subject_id ?? 0);

        if ($gradeLevelId <= 0 || $targetGradeId !== $gradeLevelId) {
            throw new \InvalidArgumentException('Pick a syllabus chapter from the same class. Another class needs its own book upload.');
        }

        if ($subjectId > 0 && $targetSubjectId !== $subjectId) {
            throw new \InvalidArgumentException('Pick a syllabus chapter from the same subject.');
        }

        if ((int) $target->id === (int) $chapter->syllabus_chapter_id) {
            return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.board']);
        }

        $duplicate = TextbookChapter::query()
            ->where('textbook_id', $chapter->textbook_id)
            ->where('syllabus_chapter_id', $target->id)
            ->whereKeyNot($chapter->id)
            ->exists();

        if ($duplicate) {
            throw new \InvalidArgumentException('This book already has that syllabus chapter.');
        }

        return DB::transaction(function () use ($chapter, $target) {
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
                'syllabus_chapter_id' => $target->id,
                'chapter_number' => $target->numericChapterNumber() ?: $chapter->chapter_number,
                'title' => $target->name,
            ]);

            return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.board']);
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
