<?php

namespace App\Mail;

use App\Models\Student;
use App\Models\Worksheet;
use App\Services\AssignmentWhatsAppNotificationService;
use App\Support\DateLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssignmentAssigned extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  list<Worksheet>  $worksheets */
    public function __construct(
        public Student $student,
        public array $worksheets,
        public string $dueDate,
        public ?string $notes = null,
    ) {}

    public function envelope(): Envelope
    {
        $worksheet = $this->worksheets[0];
        $kindLabel = count($this->worksheets) > 1 ? 'Work' : ($worksheet->isChapterScope() ? 'Test' : 'Practice');

        return new Envelope(
            subject: "Mentor Maths — {$kindLabel} assigned for {$this->student->name} ({$worksheet->set_code})",
        );
    }

    public function content(): Content
    {
        $service = app(AssignmentWhatsAppNotificationService::class);
        $dueLabel = DateLabels::formatDate($this->dueDate, 'See dashboard');

        $items = collect($this->worksheets)->map(function (Worksheet $worksheet) use ($service) {
            $worksheet->loadMissing(['topic.chapter', 'chapter']);

            if (! isset($worksheet->questions_count)) {
                $worksheet->loadCount('questions');
            }

            $questionCount = (int) ($worksheet->questions_count ?? 0);

            return [
                'set_code' => $worksheet->set_code,
                'tier_label' => $worksheet->tier_label,
                'kind_label' => $worksheet->isChapterScope() ? 'Test' : 'Practice',
                'scope_label' => $worksheet->scopeLabel(),
                'scope_line' => $this->scopeLine($worksheet),
                'question_count' => $questionCount,
                'time_estimate' => $service->estimateTimeLabel($worksheet, $questionCount),
            ];
        })->all();

        return new Content(
            view: 'emails.assignment-assigned',
            with: [
                'studentName' => $this->student->name,
                'dueLabel' => $dueLabel,
                'notes' => $this->notes,
                'items' => $items,
                'dashboardUrl' => route('dashboard'),
                'loginUrl' => route('login'),
            ],
        );
    }

    private function scopeLine(Worksheet $worksheet): ?string
    {
        if ($worksheet->isChapterScope()) {
            $chapter = $worksheet->chapter?->name;

            return $chapter ? "Chapter: {$chapter}" : null;
        }

        $topic = $worksheet->topic?->name;
        $chapter = $worksheet->topic?->chapter?->name;

        if ($topic && $chapter) {
            return "Topic: {$topic} ({$chapter})";
        }

        if ($topic) {
            return "Topic: {$topic}";
        }

        return null;
    }
}
