<?php

namespace App\Services;

use App\Models\SyllabusTopic;
use App\Models\TextbookChapter;
use Illuminate\Support\Collection;

class BookBasisPromptService
{
    /**
     * Published textbook chapters linked to a syllabus chapter (for "book basis" dropdown).
     *
     * @return list<array{id: int, label: string, book: string, chapter_title: string, topic_count: int}>
     */
    public function optionsForSyllabusChapter(?int $syllabusChapterId): array
    {
        if (! $syllabusChapterId) {
            return [];
        }

        return TextbookChapter::query()
            ->with('textbook:id,name,code')
            ->where('syllabus_chapter_id', $syllabusChapterId)
            ->where('status', TextbookChapter::STATUS_PUBLISHED)
            ->orderBy('chapter_number')
            ->get()
            ->map(function (TextbookChapter $chapter) {
                $topics = $this->topicNames($chapter);

                return [
                    'id' => $chapter->id,
                    'label' => trim(($chapter->textbook?->name ?? 'Book').' · Ch '.$chapter->chapter_number.' — '.$chapter->title),
                    'book' => $chapter->textbook?->name ?? 'Book',
                    'chapter_title' => $chapter->title,
                    'topic_count' => count($topics),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Unique topic names from published book content (extraction items).
     *
     * @return list<string>
     */
    public function topicNames(TextbookChapter $chapter): array
    {
        $names = collect($chapter->extraction_items ?? [])
            ->map(fn ($item) => trim((string) (is_array($item) ? ($item['topic'] ?? '') : '')))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values();

        if ($names->isNotEmpty()) {
            return $names->all();
        }

        // Fallback: syllabus topics for the linked chapter.
        $syllabusChapterId = $chapter->syllabus_chapter_id;
        if (! $syllabusChapterId) {
            return [];
        }

        return SyllabusTopic::query()
            ->where('syllabus_chapter_id', $syllabusChapterId)
            ->orderBy('sort_order')
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    public function bookContext(TextbookChapter $chapter, ?SyllabusTopic $focusTopic = null): string
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.board', 'syllabusChapter.syllabusVersion.gradeLevel', 'syllabusChapter.syllabusVersion.academicYear']);

        $topics = $this->topicNames($chapter);
        $topicList = $topics !== [] ? implode(', ', $topics) : '(no topic list — use syllabus topics)';

        $syllabus = $chapter->syllabusChapter;
        $version = $syllabus?->syllabusVersion;
        $grade = $chapter->textbook?->gradeLevel?->name ?? $version?->gradeLevel?->name;

        return collect([
            $version ? "Board: {$version->board->code}" : null,
            $grade ? "Class: {$grade}" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            'Book: '.($chapter->textbook?->name ?? 'Textbook'),
            "Book chapter: {$chapter->chapter_number} — {$chapter->title}",
            $syllabus ? "Syllabus chapter: {$syllabus->chapter_number} — {$syllabus->name}" : null,
            $focusTopic ? "Focus syllabus topic: {$focusTopic->name}" : null,
            "Book topics (use these — already curated from the published book content): {$topicList}",
            'Stay close to the book syllabus coverage and wording style for this chapter.',
        ])->filter()->implode("\n");
    }

    /**
     * @param  Collection<int, TextbookChapter>|list<TextbookChapter>  $chapters
     */
    public function findPublished(int $textbookChapterId, ?int $syllabusChapterId = null): ?TextbookChapter
    {
        $query = TextbookChapter::query()
            ->with(['textbook.gradeLevel', 'syllabusChapter'])
            ->whereKey($textbookChapterId)
            ->where('status', TextbookChapter::STATUS_PUBLISHED);

        if ($syllabusChapterId) {
            $query->where('syllabus_chapter_id', $syllabusChapterId);
        }

        return $query->first();
    }
}
