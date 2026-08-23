<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\PracticeSetSplitService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\WorksheetPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

class OversizedPracticeSetController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private PracticeSetSplitService $splitService,
    ) {}

    public function index(Request $request): Response
    {
        $minQuestions = max(1, min(200, $request->integer('min_questions') ?: 30));
        $classId = $request->filled('class_id') ? $request->integer('class_id') : null;
        $kind = $request->string('kind')->toString();
        if (! in_array($kind, ['all', 'practice', 'test', 'topic', 'chapter'], true)) {
            $kind = 'all';
        }

        $query = Worksheet::query()
            ->with([
                'topic.chapter.syllabusVersion.gradeLevel:id,name,sort_order',
                'chapter.syllabusVersion.gradeLevel:id,name,sort_order',
            ])
            ->withCount('questions')
            ->where(function ($q) {
                $q->whereNull('purpose')
                    ->orWhere('purpose', WorksheetPurpose::STANDARD);
            })
            ->has('questions', '>', $minQuestions);

        if ($classId) {
            $this->gradeContext->scopePracticeSets($query, $classId);
        }

        match ($kind) {
            'topic' => $query->where(function ($q) {
                $q->where('scope', PracticeSetScope::TOPIC)
                    ->orWhere(fn ($inner) => $inner->whereNull('scope')->whereNotNull('syllabus_topic_id'));
            }),
            'chapter' => $query->where('scope', PracticeSetScope::CHAPTER),
            'test' => $query->where('scope', PracticeSetScope::CHAPTER)
                ->where(function ($q) {
                    $q->where('tier', PracticeSetTier::CHAPTER_TEST)
                        ->orWhere('set_code', 'like', 'T%');
                }),
            'practice' => $query->where(function ($q) {
                $q->where(function ($topic) {
                    $topic->where('scope', PracticeSetScope::TOPIC)
                        ->orWhere(fn ($inner) => $inner->whereNull('scope')->whereNotNull('syllabus_topic_id'));
                })->orWhere(function ($chapter) {
                    $chapter->where('scope', PracticeSetScope::CHAPTER)
                        ->where(function ($tier) {
                            $tier->whereNull('tier')
                                ->orWhere('tier', '!=', PracticeSetTier::CHAPTER_TEST);
                        })
                        ->where(function ($code) {
                            $code->whereNull('set_code')
                                ->orWhere('set_code', 'not like', 'T%');
                        });
                });
            }),
            default => null,
        };

        $sets = $query
            ->orderByDesc('questions_count')
            ->orderBy('set_code')
            ->get()
            ->map(function (Worksheet $worksheet) {
                $grade = $worksheet->topic?->chapter?->syllabusVersion?->gradeLevel
                    ?? $worksheet->chapter?->syllabusVersion?->gradeLevel;
                $chapter = $worksheet->topic?->chapter ?? $worksheet->chapter;
                $count = (int) $worksheet->questions_count;
                $half = max(5, (int) ceil($count / 2));

                return [
                    'id' => $worksheet->id,
                    'set_code' => $worksheet->set_code,
                    'title' => $worksheet->display_title,
                    'set_number' => $worksheet->set_number,
                    'tier' => $worksheet->tier,
                    'tier_label' => $worksheet->tier_label,
                    'scope' => $worksheet->scope,
                    'status' => $worksheet->status,
                    'questions_count' => $count,
                    'class_name' => $grade?->name,
                    'class_id' => $grade?->id,
                    'chapter_label' => $chapter
                        ? trim(($chapter->chapter_number ? 'Ch '.$chapter->chapter_number.' — ' : '').($chapter->name ?? ''))
                        : null,
                    'topic_name' => $worksheet->topic?->name,
                    'is_chapter_test' => $worksheet->isChapterTest(),
                    'kind_label' => $worksheet->isChapterTest()
                        ? 'Chapter test'
                        : ($worksheet->isChapterScope() ? 'Chapter set' : 'Topic set'),
                    'suggested_half' => $half,
                    'related_sets' => $this->splitService->relatedSetsForSplitUi($worksheet),
                ];
            })
            ->values();

        return Inertia::render('Admin/PracticeSets/Oversized', [
            'sets' => $sets,
            'filters' => [
                'min_questions' => $minQuestions,
                'class_id' => $classId,
                'kind' => $kind,
            ],
            'classOptions' => $this->gradeContext->classLevelOptions(),
            'breakOptions' => [
                ['value' => 'half', 'label' => 'Split in half (2 parts)'],
                ['value' => '30', 'label' => 'Max 30 sums per set'],
                ['value' => '25', 'label' => 'Max 25 sums per set'],
                ['value' => '20', 'label' => 'Max 20 sums per set'],
                ['value' => '15', 'label' => 'Max 15 sums per set'],
            ],
        ]);
    }

    public function split(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:half,30,25,20,15,custom'],
            'batch_size' => ['nullable', 'integer', 'min:5', 'max:50'],
            'min_questions' => ['nullable', 'integer', 'min:1', 'max:200'],
            'class_id' => ['nullable', 'integer'],
            'kind' => ['nullable', 'string'],
        ]);

        $count = $worksheet->questions()->count();
        $mode = $validated['mode'];

        $batchSize = match ($mode) {
            'half' => max(5, (int) ceil($count / 2)),
            'custom' => (int) ($validated['batch_size'] ?? PracticeSetSplitService::DEFAULT_BATCH_SIZE),
            default => (int) $mode,
        };

        if ($batchSize >= $count) {
            return back()->with('error', "Cannot split: choose a smaller max size than {$count} sums.");
        }

        try {
            $result = $this->splitService->split($worksheet, $request->user(), $batchSize);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $codes = collect($result['plan'])->pluck('set_code')->implode(', ');

        return redirect()
            ->route('admin.practice-sets.oversized', array_filter([
                'min_questions' => $validated['min_questions'] ?? 30,
                'class_id' => $validated['class_id'] ?? null,
                'kind' => $validated['kind'] ?? 'all',
            ], fn ($value) => $value !== null && $value !== ''))
            ->with(
                'success',
                'Split '.($worksheet->set_code ?: '#'.$worksheet->id).' into '.count($result['plan'])." sets: {$codes}."
            );
    }
}
