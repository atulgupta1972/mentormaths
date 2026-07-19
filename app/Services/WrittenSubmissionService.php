<?php

namespace App\Services;

use App\Jobs\GradeWrittenSubmissionJob;
use App\Models\SetAssignment;
use App\Models\WrittenSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WrittenSubmissionService
{
    /**
     * @param  list<UploadedFile>  $files
     */
    public function store(SetAssignment $assignment, array $files): WrittenSubmission
    {
        $assignment->loadMissing('practiceSet');

        if (! $assignment->practiceSet?->isWritten()) {
            throw new \InvalidArgumentException('This assignment is not a written homework sheet.');
        }

        if (! in_array($assignment->status, [SetAssignment::STATUS_ASSIGNED, SetAssignment::STATUS_IN_PROGRESS], true)) {
            throw new \InvalidArgumentException('This assignment is no longer open for upload.');
        }

        $files = array_values(array_filter($files));

        if ($files === []) {
            throw new \InvalidArgumentException('Upload at least one photo or PDF of your completed work.');
        }

        if (count($files) > 5) {
            throw new \InvalidArgumentException('Upload up to 5 files.');
        }

        $paths = [];

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                throw new \InvalidArgumentException('Only JPG, PNG, WEBP, or PDF files are allowed.');
            }

            $directory = 'written-submissions/'.$assignment->id;
            Storage::disk('public')->makeDirectory($directory);

            $filename = Str::uuid().'.'.$extension;
            $paths[] = $file->storeAs($directory, $filename, 'public');
        }

        $existing = WrittenSubmission::query()
            ->where('set_assignment_id', $assignment->id)
            ->whereIn('status', [
                WrittenSubmission::STATUS_UPLOADED,
                WrittenSubmission::STATUS_PROCESSING,
                WrittenSubmission::STATUS_GRADED,
            ])
            ->latest('id')
            ->first();

        if ($existing && $existing->status === WrittenSubmission::STATUS_GRADED) {
            throw new \InvalidArgumentException('This homework has already been graded.');
        }

        if ($existing) {
            foreach ($existing->upload_paths ?? [] as $oldPath) {
                Storage::disk('public')->delete($oldPath);
            }
            $existing->items()->delete();
            $existing->update([
                'status' => WrittenSubmission::STATUS_UPLOADED,
                'upload_paths' => $paths,
                'score' => null,
                'max_score' => null,
                'ai_summary' => null,
                'grading_error' => null,
                'uploaded_at' => now(),
                'graded_at' => null,
            ]);
            $submission = $existing->fresh();
        } else {
            $submission = WrittenSubmission::create([
                'set_assignment_id' => $assignment->id,
                'status' => WrittenSubmission::STATUS_UPLOADED,
                'upload_paths' => $paths,
                'uploaded_at' => now(),
            ]);
        }

        if ($assignment->status === SetAssignment::STATUS_ASSIGNED) {
            $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);
        }

        GradeWrittenSubmissionJob::dispatch($submission->id);

        return $submission;
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForAssignment(SetAssignment $assignment): ?array
    {
        $submission = WrittenSubmission::query()
            ->with('items.question:id,question_text,type')
            ->where('set_assignment_id', $assignment->id)
            ->latest('id')
            ->first();

        if (! $submission) {
            return null;
        }

        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'score' => $submission->score,
            'max_score' => $submission->max_score,
            'ai_summary' => $submission->ai_summary,
            'grading_error' => $submission->grading_error,
            'uploaded_at' => $submission->uploaded_at?->toDateTimeString(),
            'graded_at' => $submission->graded_at?->toDateTimeString(),
            'upload_urls' => $submission->uploadUrls(),
            'items' => $submission->items->map(fn ($item) => [
                'question_number' => $item->question_number,
                'extracted_answer' => $item->extracted_answer,
                'step_feedback' => $item->step_feedback,
                'score' => $item->score,
                'max_score' => $item->max_score,
                'is_correct' => $item->is_correct,
                'confidence' => $item->confidence,
                'needs_review' => $item->needs_review,
            ])->values()->all(),
        ];
    }
}
