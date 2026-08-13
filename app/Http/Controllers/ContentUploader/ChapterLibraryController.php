<?php

namespace App\Http\Controllers\ContentUploader;

use App\Http\Controllers\Controller;
use App\Models\TextbookChapter;
use App\Services\ContentChapterQuestionService;
use App\Services\ContentTextbookAccessService;
use App\Services\ContentUploaderChapterLibraryService;
use App\Support\UploadedFileDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChapterLibraryController extends Controller
{
    public function __construct(
        private ContentUploaderChapterLibraryService $library,
        private ContentChapterQuestionService $questions,
        private ContentTextbookAccessService $access,
    ) {}

    public function index(Request $request): Response
    {
        $gradeId = $request->filled('grade_level_id') ? $request->integer('grade_level_id') : null;

        return Inertia::render(
            'ContentUploader/Chapters/Index',
            $this->library->index($request->user(), $gradeId),
        );
    }

    public function show(Request $request, TextbookChapter $textbookChapter): Response
    {
        $this->access->authorizeChapter($request->user(), $textbookChapter);

        return Inertia::render(
            'ContentUploader/Chapters/Show',
            $this->library->show($request->user(), $textbookChapter),
        );
    }

    public function appendMcq(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $this->access->authorizeChapter($request->user(), $textbookChapter);

        $validated = $request->validate([
            'json' => ['required', 'string'],
        ]);

        try {
            $result = $this->questions->appendJson($textbookChapter, $validated['json'], $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            "{$result['added_count']} question(s) added. Chapter now has {$result['total_count']}.",
        );
    }

    public function appendMcqZip(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $this->access->authorizeChapter($request->user(), $textbookChapter);

        $uploadedZip = $request->file('pack');
        if ($uploadedZip) {
            UploadedFileDiagnostics::assertValid($uploadedZip, 'pack');
        }

        $request->validate([
            'pack' => ['required', 'file', 'mimes:zip', 'max:51200'],
        ], [
            'pack.required' => 'Choose a .zip file with questions.json and chart images.',
            'pack.mimes' => 'Only .zip files are allowed.',
            'pack.max' => 'The zip must be under 50 MB.',
        ]);

        try {
            $result = $this->questions->appendZip($textbookChapter, $uploadedZip, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = "{$result['added_count']} question(s) added. Chapter now has {$result['total_count']}.";
        if ($result['diagram_count'] > 0) {
            $message .= " {$result['diagram_count']} chart/diagram image(s) linked.";
        }

        return back()->with('success', $message);
    }

    public function destroyQuestion(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $this->access->authorizeChapter($request->user(), $textbookChapter);

        $validated = $request->validate([
            'item_index' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $this->questions->deleteItem(
                $textbookChapter,
                (int) $validated['item_index'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Question deleted.');
    }

    public function requestDelete(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $this->access->authorizeChapter($request->user(), $textbookChapter);

        $validated = $request->validate([
            'item_index' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'min:8', 'max:2000'],
        ]);

        try {
            $this->questions->requestDelete(
                $textbookChapter,
                (int) $validated['item_index'],
                $validated['reason'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Delete request sent to admin.');
    }
}
