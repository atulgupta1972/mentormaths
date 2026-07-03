<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Models\SyllabusTopic;
use App\Services\AdminGradeContext;
use App\Services\SetAssignmentService;
use App\Support\PracticeSetTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PracticeSetTopicController extends Controller
{
    public function __construct(
        private SetAssignmentService $assignmentService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function show(Request $request, SyllabusTopic $topic): Response
    {
        $topic->load([
            'chapter.syllabusVersion.board',
            'chapter.syllabusVersion.gradeLevel',
            'practiceSets' => fn ($q) => $q->withCount('questions'),
        ]);

        $gradeLevel = $topic->chapter?->syllabusVersion?->gradeLevel;
        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        $activeYear = AcademicYear::active();
        $selectedStudentId = $request->integer('student_id') ?: null;

        $students = $this->assignmentService->activeStudentsForAssignment($activeYear?->id);

        $studentProgress = [];

        if ($selectedStudentId && $activeYear) {
            $enrollment = Student::find($selectedStudentId)?->enrollmentForYear($activeYear->id);

            if ($enrollment) {
                $studentProgress = $this->assignmentService
                    ->studentProgressForTopic($enrollment->id, $topic->id)
                    ->all();
            }
        }

        $progressBySetId = collect($studentProgress)->keyBy('practice_set_id');

        $sets = $topic->practiceSets->map(fn ($set) => [
            'id' => $set->id,
            'set_code' => $set->set_code,
            'set_number' => $set->set_number,
            'tier' => $set->tier,
            'tier_label' => $set->tier_label,
            'tier_tagline' => $set->tier_tagline,
            'display_title' => $set->display_title,
            'title' => $set->title,
            'status' => $set->status,
            'questions_count' => $set->questions_count,
            'student_progress' => $progressBySetId->get($set->id),
        ]);

        return Inertia::render('Admin/PracticeSets/TopicHub', [
            'topic' => [
                'id' => $topic->id,
                'name' => $topic->name,
                'chapter_name' => $topic->chapter->name,
                'grade_name' => $gradeLevel?->name,
                'board_code' => $topic->chapter?->syllabusVersion?->board?->code,
            ],
            'practiceSets' => $sets,
            'tiers' => PracticeSetTier::options(),
            'students' => $students,
            'selectedStudentId' => $selectedStudentId,
            'studentProgress' => $studentProgress,
            'activeYear' => $activeYear?->only(['id', 'name']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }
}
