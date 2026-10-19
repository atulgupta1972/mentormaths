<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentChapterTerm;
use App\Models\StudentEnrollment;

/**
 * After Term 1 marks, every syllabus chapter must be tagged Term 1 or Term 2
 * before the student can start drills / sums.
 */
class ChapterTermMapGate
{
    public function __construct(
        private ExamPlanService $examPlanService,
        private FirstTermMarksGate $firstTermMarksGate,
    ) {}

    public function isRequired(): bool
    {
        return (bool) config('exam_gates.chapter_terms_required', true);
    }

    public function gatePassed(?Student $student): bool
    {
        if (! $this->isRequired()) {
            return true;
        }

        $enrollment = $student?->currentEnrollment();
        if (! $enrollment) {
            return true;
        }

        // Marks gate runs first; until marks are done, do not enforce terms here.
        if ($this->firstTermMarksGate->isRequired() && ! $this->firstTermMarksGate->enrollmentPassed($enrollment)) {
            return true;
        }

        return $this->enrollmentPassed($enrollment);
    }

    public function enrollmentPassed(StudentEnrollment $enrollment): bool
    {
        $status = $this->mappingStatus($enrollment);

        return $status['satisfied'];
    }

    /**
     * @return array{
     *     required: bool,
     *     satisfied: bool,
     *     total_chapters: int,
     *     mapped_chapters: int,
     *     unmapped_chapters: int,
     *     term1_count: int,
     *     term2_count: int,
     *     message: string|null
     * }
     */
    public function statusForEnrollment(?StudentEnrollment $enrollment): array
    {
        $required = $this->isRequired();

        if (! $required || ! $enrollment) {
            return [
                'required' => $required,
                'satisfied' => true,
                'total_chapters' => 0,
                'mapped_chapters' => 0,
                'unmapped_chapters' => 0,
                'term1_count' => 0,
                'term2_count' => 0,
                'message' => null,
            ];
        }

        // Not yet at the terms step (marks still outstanding).
        if ($this->firstTermMarksGate->isRequired() && ! $this->firstTermMarksGate->enrollmentPassed($enrollment)) {
            return [
                'required' => true,
                'satisfied' => true,
                'total_chapters' => 0,
                'mapped_chapters' => 0,
                'unmapped_chapters' => 0,
                'term1_count' => 0,
                'term2_count' => 0,
                'message' => null,
            ];
        }

        $status = $this->mappingStatus($enrollment);

        return [
            'required' => true,
            'satisfied' => $status['satisfied'],
            'total_chapters' => $status['total_chapters'],
            'mapped_chapters' => $status['mapped_chapters'],
            'unmapped_chapters' => $status['unmapped_chapters'],
            'term1_count' => $status['term1_count'],
            'term2_count' => $status['term2_count'],
            'message' => $status['satisfied'] ? null : $this->blockingMessage($status),
        ];
    }

    public function blockingMessage(?array $status = null): string
    {
        if ($status && ($status['unmapped_chapters'] ?? 0) > 0) {
            return 'Map every chapter to Term 1 or Term 2 on your School study plan before starting sums ('
                .$status['unmapped_chapters'].' chapter'
                .(($status['unmapped_chapters'] === 1) ? '' : 's')
                .' still untagged).';
        }

        if ($status && (($status['term1_count'] ?? 0) === 0 || ($status['term2_count'] ?? 0) === 0)) {
            return 'Tag chapters into both Term 1 and Term 2 on your School study plan before starting sums.';
        }

        return 'Map all chapters to Term 1 and Term 2 on your School study plan before starting sums.';
    }

    /**
     * @return array{
     *     satisfied: bool,
     *     total_chapters: int,
     *     mapped_chapters: int,
     *     unmapped_chapters: int,
     *     term1_count: int,
     *     term2_count: int
     * }
     */
    private function mappingStatus(StudentEnrollment $enrollment): array
    {
        $chapterIds = $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $total = count($chapterIds);
        if ($total === 0) {
            return [
                'satisfied' => true,
                'total_chapters' => 0,
                'mapped_chapters' => 0,
                'unmapped_chapters' => 0,
                'term1_count' => 0,
                'term2_count' => 0,
            ];
        }

        $terms = StudentChapterTerm::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->whereIn('syllabus_chapter_id', $chapterIds)
            ->whereIn('term', [StudentChapterTerm::TERM_FIRST, StudentChapterTerm::TERM_SECOND])
            ->get(['syllabus_chapter_id', 'term']);

        $mappedIds = $terms->pluck('syllabus_chapter_id')->map(fn ($id) => (int) $id)->unique()->all();
        $mapped = count(array_intersect($chapterIds, $mappedIds));
        $term1 = $terms->where('term', StudentChapterTerm::TERM_FIRST)->count();
        $term2 = $terms->where('term', StudentChapterTerm::TERM_SECOND)->count();
        $unmapped = $total - $mapped;

        return [
            'satisfied' => $unmapped === 0 && $term1 > 0 && $term2 > 0,
            'total_chapters' => $total,
            'mapped_chapters' => $mapped,
            'unmapped_chapters' => $unmapped,
            'term1_count' => $term1,
            'term2_count' => $term2,
        ];
    }
}
