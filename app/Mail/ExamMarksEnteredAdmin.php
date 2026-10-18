<?php

namespace App\Mail;

use App\Models\ExamPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExamMarksEnteredAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ExamPlan $examPlan) {}

    public function envelope(): Envelope
    {
        $this->examPlan->loadMissing(['enrollment.student', 'enrollment.gradeLevel', 'enrollment.board']);

        $studentName = $this->examPlan->enrollment?->student?->name ?? 'Student';

        return new Envelope(
            subject: "Exam marks entered — {$studentName} · {$this->examPlan->title}",
        );
    }

    public function content(): Content
    {
        $this->examPlan->loadMissing([
            'enrollment.student.user',
            'enrollment.gradeLevel',
            'enrollment.board',
            'chapters',
        ]);

        $plan = $this->examPlan;
        $enrollment = $plan->enrollment;
        $student = $enrollment?->student;
        $obtained = (int) $plan->obtained_marks;
        $total = (int) $plan->total_marks;
        $pct = $total > 0 ? round(($obtained / $total) * 100) : null;

        return new Content(
            view: 'emails.exam-marks-entered-admin',
            with: [
                'studentName' => $student?->name ?? 'Student',
                'studentEmail' => $student?->user?->email,
                'gradeName' => $enrollment?->gradeLevel?->name,
                'boardName' => $enrollment?->board?->name,
                'schoolName' => $enrollment?->school_name ?? $student?->school_name,
                'examTitle' => $plan->title,
                'examTypeLabel' => $plan->typeLabel(),
                'examDate' => $plan->exam_date?->format('d M Y'),
                'obtainedMarks' => $obtained,
                'totalMarks' => $total,
                'percentLabel' => $pct !== null ? $pct.'%' : '—',
                'enteredAt' => $plan->marks_entered_at?->timezone(config('app.timezone'))->format('d M Y, h:i A'),
                'chapterNames' => $plan->chapters->pluck('name')->filter()->values()->all(),
                'examsUrl' => route('student.exams.index'),
            ],
        );
    }
}
