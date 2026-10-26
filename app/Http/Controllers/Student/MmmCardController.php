<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\MmmCard;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MmmCardController extends Controller
{
    public function __construct(private MmmCardService $mmmCards) {}

    public function catalog(): Response
    {
        $user = auth()->user();
        abort_unless($user, 403);

        $enrollment = $user->isStudent() ? $this->activeEnrollment($user) : null;
        $gradeId = $enrollment?->grade_level_id;

        $query = MmmCard::query()
            ->where('status', MmmCard::STATUS_PUBLISHED)
            ->with(['chapterHead', 'gradeLevels'])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($user->isStudent()) {
            $cards = $gradeId
                ? $query->whereHas('gradeLevels', fn ($grades) => $grades->where('grade_levels.id', $gradeId))->get()
                : collect();
        } else {
            $cards = $query->get();
        }

        return Inertia::render('Student/MmmCards/Catalog', [
            'class_name' => $enrollment?->gradeLevel?->name,
            'show_classes' => ! $user->isStudent(),
            'groups' => $this->catalogGroups($cards),
        ]);
    }

    public function show(Request $request, MmmCard $mmmCard): Response
    {
        $user = $this->abortUnlessCanLearn($mmmCard);

        $exerciseIndex = $request->filled('exercise')
            ? max(0, $request->integer('exercise') - 1)
            : null;

        $gradeId = $user->isStudent() ? $this->activeEnrollment($user)?->grade_level_id : null;
        $next = $this->mmmCards->nextPartCard($mmmCard, $gradeId);

        return Inertia::render('Student/MmmCards/Play', [
            ...$this->mmmCards->playPayload($mmmCard, $user, $exerciseIndex),
            'check_url' => route('student.mmm-cards.check', $mmmCard),
            'back_url' => route('learn-methods.index'),
            'is_staff_preview' => false,
            'next_part' => $next ? [
                'heading' => $next->heading ?: $next->title,
                'url' => route('student.mmm-cards.show', $next),
            ] : null,
        ]);
    }

    public function check(Request $request, MmmCard $mmmCard)
    {
        $user = $this->abortUnlessCanLearn($mmmCard);

        $validated = $request->validate([
            'exercise_id' => ['required', 'integer', 'exists:mmm_card_exercises,id'],
            'rows' => ['nullable', 'array'],
            'grand_total' => ['nullable'],
            'grand_count' => ['nullable'],
            'equation' => ['nullable', 'string', 'max:200'],
            'coin_equation' => ['nullable', 'string', 'max:200'],
            'eq' => ['nullable', 'string', 'max:200'],
            'x_value' => ['nullable', 'string', 'max:40'],
            'step_key' => ['nullable', 'string', 'max:40'],
            'value' => ['nullable', 'string', 'max:40'],
            'values' => ['nullable', 'array'],
        ]);

        $exercise = $mmmCard->exercises()->whereKey($validated['exercise_id'])->firstOrFail();
        $result = $this->mmmCards->checkSubmission($mmmCard, $exercise, $validated);

        $passed = array_key_exists('all_ok', $result)
            ? ! empty($result['all_ok'])
            : ! empty($result['ok']);
        if ($passed) {
            $this->mmmCards->recordExercisePassed($user, $mmmCard, $exercise);
        }

        $mmmCard->loadMissing('exercises');
        $orderedIds = $mmmCard->exercises->pluck('id')->values();
        $index = $orderedIds->search((int) $exercise->id);
        $hasNext = $index !== false && $index < $orderedIds->count() - 1;

        return response()->json([
            ...$result,
            'has_next' => $hasNext,
            'next_exercise' => $hasNext ? (int) $index + 2 : null,
            'received_equation' => $validated['equation'] ?? null,
        ]);
    }

    private function abortUnlessCanLearn(MmmCard $card): User
    {
        $user = auth()->user();
        abort_unless($user, 403);
        abort_unless($card->isPublished(), 404);

        if ($user->isStudent()) {
            $gradeId = $this->activeEnrollment($user)?->grade_level_id;
            abort_unless(
                $gradeId && $card->gradeLevels()->where('grade_levels.id', $gradeId)->exists(),
                404,
            );
        }

        return $user;
    }

    private function activeEnrollment(User $user): ?StudentEnrollment
    {
        $year = AcademicYear::active();
        if (! $year || ! $user->student) {
            return null;
        }

        return $user->student->enrollments()
            ->with('gradeLevel')
            ->where('academic_year_id', $year->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->first();
    }

    /**
     * @param  Collection<int, MmmCard>  $cards
     * @return list<array{head: string, subs: list<array{name: string, methods: list<array<string, mixed>>}>}>
     */
    private function catalogGroups(Collection $cards): array
    {
        $buckets = [];

        foreach ($cards as $card) {
            $head = $card->chapterHead;
            $headKey = $head?->id ?: 0;
            $sub = trim((string) $card->sub_heading) !== '' ? trim((string) $card->sub_heading) : 'General';

            $buckets[$headKey] ??= [
                'head' => $head?->name ?: 'Not classified',
                'sort' => $head ? sprintf('%05d-%s', (int) $head->sort_order, $head->name) : '99999',
                'subs' => [],
            ];
            $buckets[$headKey]['subs'][$sub] ??= [];
            $buckets[$headKey]['subs'][$sub][] = [
                'id' => $card->id,
                'heading' => $card->heading,
                'about' => $card->method_blurb,
                'classes' => $card->gradeLevels->sortBy('sort_order')->pluck('name')->values(),
                'open_url' => route('student.mmm-cards.show', $card),
            ];
        }

        return collect($buckets)
            ->sortBy('sort')
            ->values()
            ->map(function (array $bucket) {
                $subs = collect($bucket['subs'])
                    ->sortKeys()
                    ->map(fn (array $methods, string $name) => [
                        'name' => $name,
                        'methods' => $methods,
                    ])
                    ->values()
                    ->all();

                return [
                    'head' => $bucket['head'],
                    'subs' => $subs,
                ];
            })
            ->all();
    }
}
