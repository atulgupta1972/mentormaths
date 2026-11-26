<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Services\StudentMentorService;
use App\Support\EnrollmentSource;
use App\Support\StudentIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MentorDirectoryController extends Controller
{
    public function __construct(
        private StudentMentorService $mentorService,
    ) {}

    public function index(): Response
    {
        $teachers = CoachingClassTeacher::query()
            ->with(['coachingClass:id,name,city', 'user:id,name,mobile,email'])
            ->withCount(['students' => fn ($q) => $q->where('enrollment_source', EnrollmentSource::COACHING)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (CoachingClassTeacher $teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->name,
                'mobile' => $teacher->mobile,
                'email' => $teacher->email,
                'is_active' => $teacher->is_active,
                'students_count' => (int) $teacher->students_count,
                'class_name' => $teacher->coachingClass?->name,
                'class_city' => $teacher->coachingClass?->city,
                'user_id' => $teacher->user_id,
                'user_mobile' => $teacher->user?->mobile,
            ]);

        $individualMentors = Student::query()
            ->where('enrollment_source', EnrollmentSource::INDIVIDUAL)
            ->where(function ($query) {
                $query
                    ->where(fn ($q) => $q->where('notify_parent1_mobile', true)->whereNotNull('parent1_mobile')->where('parent1_mobile', '!=', ''))
                    ->orWhere(fn ($q) => $q->where('notify_parent2_mobile', true)->whereNotNull('parent2_mobile')->where('parent2_mobile', '!=', ''));
            })
            ->get(['id', 'name', 'parent1_name', 'parent1_mobile', 'notify_parent1_mobile', 'parent2_name', 'parent2_mobile', 'notify_parent2_mobile'])
            ->map(function (Student $student) {
                $mentor = $this->mentorService->resolve($student);

                return [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'mentor_name' => $mentor['name'],
                    'mentor_mobile' => $mentor['mobile'],
                    'mentor_label' => $mentor['label'],
                    'mentor_type' => $mentor['type'],
                ];
            })
            ->filter(fn (array $row) => filled($row['mentor_mobile']))
            ->values();

        return Inertia::render('Admin/Mentors/Index', [
            'teachers' => $teachers,
            'individualMentors' => $individualMentors,
        ]);
    }

    public function updateTeacher(Request $request, CoachingClassTeacher $teacher): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'sync_linked_user_mobile' => ['sometimes', 'boolean'],
        ]);

        $oldMobile = StudentIdentity::normalizeMobile($teacher->mobile);
        $newMobile = StudentIdentity::normalizeMobile($validated['mobile']);

        $teacher->update([
            'name' => $validated['name'],
            'mobile' => $validated['mobile'],
            'email' => $validated['email'] ?? $teacher->email,
        ]);

        if (! $teacher->user_id) {
            $this->mentorService->linkTeacherToMentorUser($teacher->fresh());
        } elseif ($request->boolean('sync_linked_user_mobile', true) && $newMobile && $newMobile !== $oldMobile) {
            $teacher->loadMissing('user');
            if ($teacher->user) {
                $teacher->user->update(['mobile' => $validated['mobile']]);
            }
        }

        // Keep registration-copied parent1 contacts aligned for coaching students.
        Student::query()
            ->where('coaching_class_teacher_id', $teacher->id)
            ->where('enrollment_source', EnrollmentSource::COACHING)
            ->update([
                'parent1_name' => $validated['name'],
                'parent1_mobile' => $validated['mobile'],
                'notify_parent1_mobile' => true,
            ]);

        $count = (int) Student::query()
            ->where('coaching_class_teacher_id', $teacher->id)
            ->where('enrollment_source', EnrollmentSource::COACHING)
            ->count();

        return back()->with(
            'success',
            "Mentor {$validated['name']} updated. {$count} coaching student".($count === 1 ? '' : 's').' will use the new WhatsApp number.',
        );
    }
}
