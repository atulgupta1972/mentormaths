<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
}
