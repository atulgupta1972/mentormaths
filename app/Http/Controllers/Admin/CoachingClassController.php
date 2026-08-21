<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Services\IndiaPincodeLookup;
use App\Services\StudentMentorService;
use App\Support\EnrollmentSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CoachingClassController extends Controller
{
    public function __construct(
        private IndiaPincodeLookup $pincodeLookup,
        private StudentMentorService $mentorService,
    ) {}

    public function index(): Response
    {
        $classes = CoachingClass::query()
            ->withCount(['teachers', 'students'])
            ->with([
                'teachers' => fn ($q) => $q->orderBy('sort_order')->orderBy('name'),
                'students' => fn ($q) => $q
                    ->with(['user:id,email,is_active', 'coachingClassTeacher:id,name'])
                    ->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        $mappableStudents = Student::query()
            ->with(['user:id,email,is_active', 'coachingClass:id,name'])
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'parent1_mobile', 'coaching_class_id', 'coaching_class_teacher_id', 'enrollment_source', 'user_id'])
            ->map(fn (Student $student) => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->user?->email,
                'parent1_mobile' => $student->parent1_mobile,
                'coaching_class_id' => $student->coaching_class_id,
                'coaching_class_name' => $student->coachingClass?->name,
                'enrollment_source' => $student->enrollment_source,
            ]);

        return Inertia::render('Admin/Masters/CoachingClasses/Index', [
            'classes' => $classes,
            'mappableStudents' => $mappableStudents,
        ]);
    }

    public function lookupPincode(string $pinCode): JsonResponse
    {
        return response()->json($this->pincodeLookup->lookup($pinCode));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatedClassPayload($request);

        CoachingClass::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', 'Coaching class created.');
    }

    public function update(Request $request, CoachingClass $coachingClass): RedirectResponse
    {
        $validated = $this->validatedClassPayload($request);

        $coachingClass->update([
            ...$validated,
            'is_active' => $validated['is_active'] ?? $coachingClass->is_active,
        ]);

        return back()->with('success', 'Coaching class updated.');
    }

    public function toggleActive(CoachingClass $coachingClass): RedirectResponse
    {
        $coachingClass->update(['is_active' => ! $coachingClass->is_active]);

        return back()->with('success', $coachingClass->is_active ? 'Class activated.' : 'Class deactivated.');
    }

    public function mapStudents(Request $request, CoachingClass $coachingClass): RedirectResponse
    {
        $validated = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'coaching_class_teacher_id' => [
                'required',
                'integer',
                Rule::exists('coaching_class_teachers', 'id')->where(
                    fn ($q) => $q->where('coaching_class_id', $coachingClass->id)->where('is_active', true),
                ),
            ],
        ], [
            'student_ids.required' => 'Select at least one active student.',
            'coaching_class_teacher_id.required' => 'Select the teacher / mentor for these students.',
        ]);

        if (! $coachingClass->is_active) {
            return back()->with('error', 'Activate the coaching class before mapping students.');
        }

        $mapped = 0;

        DB::transaction(function () use ($validated, $coachingClass, &$mapped) {
            $students = Student::query()
                ->with('user')
                ->whereIn('id', $validated['student_ids'])
                ->whereHas('user', fn ($q) => $q->where('is_active', true))
                ->get();

            foreach ($students as $student) {
                $this->mentorService->map($student, [
                    'enrollment_source' => EnrollmentSource::COACHING,
                    'coaching_class_id' => $coachingClass->id,
                    'coaching_class_teacher_id' => $validated['coaching_class_teacher_id'],
                ]);

                $enrollment = $student->currentEnrollment();
                if ($enrollment) {
                    $enrollment->update([
                        'enrollment_source' => EnrollmentSource::COACHING,
                        'coaching_class_id' => $coachingClass->id,
                    ]);
                }

                $mapped++;
            }
        });

        if ($mapped === 0) {
            return back()->with('error', 'No active-login students were mapped. Check selection.');
        }

        return back()->with('success', "Mapped {$mapped} student(s) to {$coachingClass->name}.");
    }

    public function storeTeacher(Request $request, CoachingClass $coachingClass): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $coachingClass->teachers()->create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => (int) $coachingClass->teachers()->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Teacher added.');
    }

    public function updateTeacher(Request $request, CoachingClassTeacher $teacher): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'coaching_class_id' => [
                'sometimes',
                'integer',
                Rule::exists('coaching_classes', 'id'),
            ],
        ]);

        $teacher->update([
            ...$validated,
            'is_active' => $validated['is_active'] ?? $teacher->is_active,
        ]);

        return back()->with('success', 'Teacher updated.');
    }

    public function destroyTeacher(CoachingClassTeacher $teacher): RedirectResponse
    {
        if ($teacher->students()->exists()) {
            return back()->with('error', 'Cannot delete: students are mapped to this teacher. Deactivate instead.');
        }

        $teacher->delete();

        return back()->with('success', 'Teacher removed.');
    }

    /** @return array<string, mixed> */
    private function validatedClassPayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'pin_code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'state' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ], [
            'pin_code.regex' => 'PIN code must be exactly 6 digits.',
        ]);
    }
}
