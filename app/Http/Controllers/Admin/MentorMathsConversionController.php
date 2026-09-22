<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TextbookChapter;
use App\Services\AdminGradeContext;
use App\Services\GeminiFillBlankConversionService;
use App\Services\MentorMathsConversionPackService;
use App\Services\MentorMathsConversionQueueService;
use App\Services\TextbookChapterFillBlankImportService;
use App\Services\TextbookChapterPublishService;
use App\Services\TextbookSetCodeService;
use App\Support\MentorMathsSourceRef;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MentorMathsConversionController extends Controller
{
    public function __construct(
        private MentorMathsConversionQueueService $queue,
        private AdminGradeContext $gradeContext,
        private GeminiFillBlankConversionService $geminiFillBlank,
        private TextbookChapterPublishService $publishService,
        private TextbookChapterFillBlankImportService $fillBlankImportService,
        private TextbookSetCodeService $setCodeService,
        private MentorMathsConversionPackService $conversionPacks,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $gradeId = $request->filled('grade_level_id')
            ? (int) $request->input('grade_level_id')
            : ($gradeLevel?->id);

        $bookId = $request->filled('textbook_id') ? (int) $request->input('textbook_id') : null;

        $payload = $this->queue->queue($gradeId, $bookId);

        return Inertia::render('Admin/Textbooks/MentorMathsQueue', $payload);
    }

    public function show(Request $request, TextbookChapter $textbookChapter): Response|RedirectResponse
    {
        $textbookChapter->load([
            'textbook.gradeLevel',
            'syllabusChapter',
            'fillBlankWorksheet',
            'mcqWorksheet',
            'writtenWorksheet',
        ]);

        if (! $this->queue->isConversionCandidate($textbookChapter->textbook)) {
            return redirect()
                ->route('admin.mentormaths-conversion.index')
                ->with('error', 'This book is not on the MentorMaths conversion queue.');
        }

        // Done chapters stay viewable (export / re-check), they are just not "pending".
        $alreadyDone = $this->queue->chapterIsDone($textbookChapter);

        $row = $this->queue->chapterRow($textbookChapter);
        $gradeId = (int) ($textbookChapter->textbook?->grade_level_id ?? 0);
        $classNumber = null;
        if ($textbookChapter->textbook?->gradeLevel?->name
            && preg_match('/(\d+)/', $textbookChapter->textbook->gradeLevel->name, $m)) {
            $classNumber = (int) $m[1];
        }

        $suggested = $this->queue->suggestMentorMathsIdentity($gradeId);
        $gemini = null;
        $items = is_array($textbookChapter->extraction_items) ? $textbookChapter->extraction_items : [];

        if ($items !== [] && ($textbookChapter->textbook?->isMentorMathsPracticeLine() ?? false)) {
            try {
                $gemini = $this->geminiFillBlank->payload($textbookChapter);
                $remaining = $this->geminiFillBlank->remainingRewritePack($textbookChapter);
                if (($remaining['remaining_count'] ?? 0) > 0) {
                    $gemini['remaining_count'] = $remaining['remaining_count'];
                    $gemini['skipped_rewrite_prompt'] = $remaining['prompt'];
                    $gemini['skipped_rewrite_reference_json'] = $remaining['reference_json'];
                }

                $blockerPack = $this->geminiFillBlank->publishBlockerRewritePack($textbookChapter);
                if (($blockerPack['blocker_count'] ?? 0) > 0) {
                    $gemini['publish_blocker_count'] = $blockerPack['blocker_count'];
                    $gemini['publish_blocker_rewrite_prompt'] = $blockerPack['prompt'];
                    $gemini['publish_blocker_rewrite_reference_json'] = $blockerPack['reference_json'];
                }
            } catch (\Throwable $e) {
                report($e);
                $gemini = null;
            }
        }

        return Inertia::render('Admin/Textbooks/MentorMathsConvert', [
            'chapter' => array_merge($row, [
                'pdf_url' => null,
                'has_pdf' => filled($textbookChapter->pdf_path),
                'fill_blank_ready_count' => $this->fillBlankImportService->fillBlankReadyCount($items),
            ]),
            'suggested' => $suggested,
            'source_ref_options' => MentorMathsSourceRef::options($classNumber),
            'rebrand' => [
                'book_name' => $textbookChapter->textbook?->isMentorMathsPracticeLine()
                    ? $textbookChapter->textbook->name
                    : $suggested['name'],
                'book_code' => $textbookChapter->textbook?->isMentorMathsPracticeLine()
                    ? $textbookChapter->textbook->code
                    : $suggested['code'],
                'source_ref' => $textbookChapter->textbook?->source_ref
                    ?: ($row['guessed_source_ref'] ?? ''),
            ],
            'gemini' => $gemini,
            'queue_step' => $row['queue_step'],
            'already_done' => $alreadyDone,
        ]);
    }

    public function rebrand(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $textbookChapter->load('textbook.gradeLevel');

        abort_unless($textbookChapter->textbook, 404);

        $classNumber = null;
        if ($textbookChapter->textbook->gradeLevel?->name
            && preg_match('/(\d+)/', $textbookChapter->textbook->gradeLevel->name, $m)) {
            $classNumber = (int) $m[1];
        }

        $validated = $request->validate([
            'book_name' => ['required', 'string', 'max:255'],
            'book_code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'source_ref' => ['required', 'string', Rule::in(MentorMathsSourceRef::values($classNumber))],
        ]);

        try {
            $this->queue->rebrandTextbook(
                $textbookChapter->textbook,
                $validated['book_name'],
                $validated['book_code'],
                $validated['source_ref'],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }

        return redirect()
            ->route('admin.mentormaths-conversion.show', $textbookChapter)
            ->with('success', 'Book rebranded to MentorMaths. Next: transform questions to fill-in-blanks.');
    }

    public function previewGemini(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(count($textbookChapter->extraction_items ?? []) > 0, 422, 'Import questions first.');

        $validated = $request->validate([
            'json' => ['required', 'string', 'min:20'],
            'source' => ['nullable', 'string', Rule::in(['main', 'rewrite', 'skipped', 'blocker'])],
        ]);

        try {
            $preview = $this->geminiFillBlank->preview($textbookChapter, $validated['json']);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $redirect = back()->with('conversion_gemini_preview', $preview);

        return match ($validated['source'] ?? 'main') {
            'rewrite' => $redirect->with('conversion_rewrite_json', $validated['json']),
            'skipped' => $redirect->with('conversion_skipped_json', $validated['json']),
            'blocker' => $redirect->with('conversion_blocker_json', $validated['json']),
            default => $redirect->with('conversion_gemini_json', $validated['json']),
        };
    }

    public function applyGemini(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $textbookChapter->loadMissing('textbook');
        abort_unless(count($textbookChapter->extraction_items ?? []) > 0, 422, 'Import questions first.');
        abort_unless($textbookChapter->textbook?->isMentorMathsPracticeLine(), 422, 'Rebrand the book to MentorMaths first.');

        $validated = $request->validate([
            'json' => ['required', 'string', 'min:20'],
        ]);

        try {
            $result = $this->geminiFillBlank->applyForChapter($textbookChapter, $validated['json']);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.mentormaths-conversion.show', $textbookChapter)
            ->with('success', sprintf(
                'Transform applied: +%d this pass · %d fill-in-blank ready total · %d still need invent/rewrite.',
                $result['convertible_count'],
                $result['ready_count'] ?? $result['convertible_count'],
                $result['not_possible_count'],
            ));
    }

    public function discardPublishBlockers(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $textbookChapter->loadMissing('textbook');
        abort_unless($textbookChapter->textbook?->isMentorMathsPracticeLine(), 422, 'Rebrand the book to MentorMaths first.');

        $validated = $request->validate([
            'indexes' => ['nullable', 'array'],
            'indexes.*' => ['integer', 'min:0'],
            'all' => ['nullable', 'boolean'],
        ]);

        $indexes = ! empty($validated['all'])
            ? null
            : array_values($validated['indexes'] ?? []);

        if ($indexes === [] && empty($validated['all'])) {
            return back()->with('error', 'Pick at least one stuck question to discard.');
        }

        try {
            $result = $this->geminiFillBlank->discardPublishBlockers(
                $textbookChapter,
                empty($validated['all']) ? $indexes : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $msg = sprintf(
            'Discarded %d stuck blank(s). %d fill-blank ready · %d still blocking.',
            $result['discarded'],
            $result['ready_count'],
            $result['remaining_blockers'],
        );

        if ($result['remaining_blockers'] === 0) {
            $msg .= ' You can publish now.';
        }

        return redirect()
            ->route('admin.mentormaths-conversion.show', $textbookChapter)
            ->with('success', $msg);
    }

    public function publish(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $textbookChapter->loadMissing('textbook');
        abort_unless($textbookChapter->textbook?->isMentorMathsPracticeLine(), 422, 'Rebrand the book to MentorMaths first.');

        try {
            $chapter = $this->publishService->publishFillBlankAndWritten($textbookChapter, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $codes = $this->setCodeService->codes($chapter);

        return redirect()
            ->route('admin.mentormaths-conversion.index')
            ->with(
                'success',
                "Done — {$chapter->textbook?->name} Ch {$chapter->chapter_number} published as fill-blank {$codes['fill_blank']}. Removed from queue.",
            );
    }

    public function exportPack(Request $request): StreamedResponse|RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:textbook_chapters,id'],
        ]);

        try {
            $written = $this->conversionPacks->writePackFile($validated['ids']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $filename = basename($written['path']);

        return response()->streamDownload(function () use ($written) {
            echo Storage::disk('local')->get($written['path']);
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function importPack(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pack' => ['required', 'file', 'mimes:json,txt', 'max:20480'],
            'publish' => ['nullable', 'boolean'],
        ]);

        $raw = file_get_contents($validated['pack']->getRealPath());
        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            return back()->with('error', 'Pack file is not valid JSON.');
        }

        $publish = $request->boolean('publish', true);

        try {
            $result = $this->conversionPacks->importPack($decoded, $request->user(), $publish);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $ok = count($result['imported']);
        $fail = count($result['errors']);
        $msg = "Imported {$ok} chapter(s)".($publish ? ' (publish attempted)' : ' (saved only)').'.';
        if ($fail > 0) {
            $msg .= ' '.$fail.' failed: '.implode(' | ', array_slice($result['errors'], 0, 3));
        }

        return redirect()
            ->route('admin.mentormaths-conversion.index')
            ->with($fail > 0 && $ok === 0 ? 'error' : 'success', $msg);
    }
}
