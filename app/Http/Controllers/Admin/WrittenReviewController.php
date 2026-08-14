<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Services\AdminGradeContext;
use App\Services\ClassAssignmentService;
use App\Services\SetAssignmentService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WrittenReviewController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private ClassAssignmentService $classAssignmentService,
        private SetAssignmentService $setAssignmentService,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $activeYear = AcademicYear::active();

        $boardOptions = $gradeLevel
            ? $this->classAssignmentService->boardsForGrade($gradeLevel)
            : [];
        $boardId = $request->integer('board_id') ?: null;

        if ($boardId && ! collect($boardOptions)->contains(fn (array $board) => $board['id'] === $boardId)) {
            $boardId = null;
        }

        if ($gradeLevel && ! $boardId) {
            $boardId = $this->classAssignmentService->defaultBoardIdForGrade($gradeLevel);
        }

        $selectedStudentId = $request->integer('student_id') ?: null;

        $setStatusBoard = ['students' => [], 'chapters' => []];
        $classStudents = [];
        $queue = [
            'upload_pending' => 0,
            'under_review' => 0,
            'teacher_check' => 0,
            'failed' => 0,
            'graded' => 0,
        ];

        if ($gradeLevel && $activeYear) {
            $setStatusBoard = $this->classAssignmentService->classSetStatusBoard($gradeLevel, null, $boardId);
            $setStatusBoard = $this->writtenOnlyBoard($setStatusBoard, $selectedStudentId);
            $classStudents = $this->setAssignmentService
                ->activeStudentsForAssignment($activeYear->id, $gradeLevel->id, $boardId)
                ->values()
                ->all();
            $queue = $this->queueCounts($setStatusBoard);
        }

        return Inertia::render('Admin/WrittenReview/Index', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'activeYear' => $activeYear?->only(['id', 'name']),
            'boardOptions' => $boardOptions,
            'selectedBoardId' => $boardId,
            'selectedStudentId' => $selectedStudentId,
            'classStudents' => $classStudents,
            'setStatusBoard' => $setStatusBoard,
            'queue' => $queue,
            'grades' => $this->gradeContext->classLevels()
                ->map(fn (GradeLevel $grade) => $grade->only(['id', 'name', 'sort_order']))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  array{students: list<mixed>, chapters: list<array<string, mixed>>}  $board
     * @return array{students: list<mixed>, chapters: list<array<string, mixed>>}
     */
    private function writtenOnlyBoard(array $board, ?int $studentId): array
    {
        $chapters = collect($board['chapters'] ?? [])
            ->map(function (array $chapter) use ($studentId) {
                $sets = collect($chapter['sets'] ?? [])
                    ->filter(fn (array $set) => ($set['delivery_mode'] ?? null) === 'written')
                    ->map(function (array $set) use ($studentId) {
                        if (! $studentId) {
                            return $set;
                        }

                        $set['students'] = collect($set['students'] ?? [])
                            ->filter(fn (array $row) => (int) ($row['student_id'] ?? 0) === $studentId)
                            ->values()
                            ->all();

                        return $set;
                    })
                    ->filter(fn (array $set) => ($set['students'] ?? []) !== [])
                    ->values()
                    ->all();

                $chapter['sets'] = $sets;

                return $chapter;
            })
            ->filter(fn (array $chapter) => ($chapter['sets'] ?? []) !== [])
            ->values()
            ->all();

        $students = collect($board['students'] ?? [])
            ->when($studentId, fn ($rows) => $rows->filter(fn ($student) => (int) ($student['id'] ?? 0) === $studentId))
            ->values()
            ->all();

        return [
            'students' => $students,
            'chapters' => $chapters,
        ];
    }

    /**
     * @param  array{students: list<mixed>, chapters: list<array<string, mixed>>}  $board
     * @return array{upload_pending: int, under_review: int, teacher_check: int, failed: int, graded: int}
     */
    private function queueCounts(array $board): array
    {
        $counts = [
            'upload_pending' => 0,
            'under_review' => 0,
            'teacher_check' => 0,
            'failed' => 0,
            'graded' => 0,
        ];

        foreach ($board['chapters'] ?? [] as $chapter) {
            foreach ($chapter['sets'] ?? [] as $set) {
                foreach ($set['students'] ?? [] as $row) {
                    $progress = $row['progress'] ?? null;
                    if (! $progress) {
                        continue;
                    }

                    $status = $progress['written_submission_status'] ?? null;

                    if ($status === null) {
                        $counts['upload_pending']++;
                    } elseif (in_array($status, ['uploaded', 'processing'], true)) {
                        $counts['under_review']++;
                    } elseif ($status === 'failed') {
                        $counts['failed']++;
                    } elseif ($status === 'graded') {
                        if (! empty($progress['needs_teacher_review'])) {
                            $counts['teacher_check']++;
                        } else {
                            $counts['graded']++;
                        }
                    }
                }
            }
        }

        return $counts;
    }
}
