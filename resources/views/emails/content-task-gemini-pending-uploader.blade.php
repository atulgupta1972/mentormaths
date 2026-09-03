<p>Hi {{ $uploader->name }},</p>

<p>
    Gemini MCQ verification is still pending for
    <strong>{{ $tasks->count() }}</strong>
    content task{{ $tasks->count() === 1 ? '' : 's' }}.
</p>

<p>
    Please complete the Gemini prompt verification on each pending task
    before starting any new chapter upload/import.
</p>

<p><strong>Pending tasks:</strong></p>

@foreach ($tasks as $task)
    @php($chapter = $task->textbookChapter)
    @php($grade = $chapter?->textbook?->gradeLevel)
    <div style="margin: 0 0 12px; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
        <p style="margin: 0 0 6px;">
            <strong>{{ $grade?->name ?? 'Class' }}</strong>
            @if ($chapter?->chapter_number !== null)
                · Ch {{ $chapter->chapter_number }}
            @endif
        </p>
        <p style="margin: 0 0 6px; color: #475569;">
            {{ $chapter?->title ?? 'Untitled chapter' }}
        </p>
        <p style="margin: 0;">
            <a href="{{ route('content.tasks.show', $task) }}">Open this task →</a>
        </p>
    </div>
@endforeach

<p>
    My content tasks:
    <a href="{{ $tasksUrl }}">{{ $tasksUrl }}</a>
</p>

@if ($guideUrl)
    <p>
        Screen-wise guide:
        <a href="{{ $guideUrl }}">{{ $guideUrl }}</a>
    </p>
@endif

<p>Thank you,<br>{{ config('app.name') }}</p>

