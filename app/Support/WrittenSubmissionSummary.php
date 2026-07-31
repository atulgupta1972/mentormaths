<?php

namespace App\Support;

use App\Models\SetAssignment;
use App\Models\WrittenSubmission;

class WrittenSubmissionSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function forEmail(WrittenSubmission $submission): array
    {
        $submission->loadMissing([
            'assignment.enrollment.student',
            'assignment.practiceSet.chapter',
            'assignment.practiceSet.topic.chapter',
            'items',
        ]);

        $assignment = $submission->assignment;
        $worksheet = $assignment->practiceSet;
        $student = $assignment->enrollment->student;

        $wrongCount = $submission->items->where('is_correct', false)->count();
        $viewUrl = route('student.written-assignments.show', $assignment);

        return [
            'student_name' => $student?->name ?? 'Student',
            'set_code' => $worksheet->set_code,
            'set_number' => $worksheet->set_number,
            'kind_label' => $worksheet->isChapterTest() ? 'Written test' : 'Written practice',
            'chapter_name' => $worksheet->isChapterScope()
                ? $worksheet->chapter?->name
                : $worksheet->topic?->chapter?->name,
            'topic_name' => $worksheet->isChapterScope()
                ? ($worksheet->isChapterTest() ? 'Chapter test' : 'Chapter practice')
                : $worksheet->topic?->name,
            'score' => $submission->score,
            'max_score' => $submission->max_score,
            'score_label' => ScoreLabel::format($submission->score, $submission->max_score),
            'handwriting_label' => $submission->handwritingLabel(),
            'teacher_remarks' => $submission->teacher_remarks,
            'ai_summary' => $submission->ai_summary,
            'wrong_count' => $wrongCount,
            'view_url' => $viewUrl,
            'dashboard_url' => route('dashboard'),
            'target_label' => $assignment->due_date
                ? DateLabels::formatDate($assignment->due_date->toDateString())
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forFailedEmail(WrittenSubmission $submission): array
    {
        $summary = self::forEmail($submission);
        $summary['grading_error'] = $submission->grading_error;

        return $summary;
    }
}
