<p>Hi {{ $uploader->name }},</p>

<p>
    You have been assigned
    <strong>{{ $tasks->count() }}</strong>
    textbook chapter{{ $tasks->count() === 1 ? '' : 's' }} for
    <strong>{{ $tasks->contains(fn ($task) => $task->isFillBlankConversion()) ? 'fill-in-blank conversion' : 'content upload (MCQ)' }}</strong>
    on Mentor Maths.
</p>

<p>
    @if ($tasks->contains(fn ($task) => $task->isFillBlankConversion()) && ! $tasks->contains(fn ($task) => ! $task->isFillBlankConversion()))
        <strong>Work type:</strong> Convert published MCQs to fill-in-blank (and written).
        Skip number-names. Check every included blank as a student (key hidden), then submit for admin publish.
    @else
        <strong>Work type:</strong> Textbook chapter MCQ upload &amp; verification
        (not doubt-solving / mentoring). You upload questions for the chapter(s) below,
        check every question, then submit for admin publish.
    @endif
</p>

<p><strong>Assigned work (full details):</strong></p>
@foreach ($tasks as $task)
    @php($chapter = $task->textbookChapter)
    @php($book = $chapter?->textbook)
    @php($grade = $book?->gradeLevel)
    @php($syllabus = $chapter?->syllabusChapter)
    <div style="margin: 0 0 16px; padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
        <p style="margin: 0 0 6px;">
            <strong>{{ $grade->name ?? 'Class' }}</strong>
            · {{ $book->name ?? 'Textbook' }}{{ $book?->code ? ' ('.$book->code.')' : '' }}
        </p>
        <p style="margin: 0 0 6px;">
            <strong>Chapter:</strong>
            Ch {{ $chapter->chapter_number ?? '?' }} — {{ $chapter->title ?? 'Untitled' }}
            @if ($syllabus?->name && $syllabus->name !== $chapter->title)
                <br><span style="color: #475569;">Syllabus: {{ $syllabus->name }}</span>
            @endif
        </p>
        <p style="margin: 0 0 6px;">
            <strong>What to do:</strong>
            @if ($task->isFillBlankConversion())
                Convert each MCQ to a fill-in-blank, Check as a student, skip number-names.
            @else
                Upload MCQs for this chapter (from the textbook PDF / Cursor JSON),
                then verify each question in the checklist.
            @endif
        </p>
        <p style="margin: 0;">
            <strong>Offered rate:</strong> {{ $task->rateDescription() }}
            @if ($task->admin_notes)
                <br><strong>Admin note:</strong> {{ $task->admin_notes }}
            @endif
        </p>
        <p style="margin: 8px 0 0;">
            <a href="{{ route('content.tasks.show', $task) }}">Open this task →</a>
        </p>
    </div>
@endforeach

<p><strong>Brief process:</strong></p>
<ol>
    <li>Log in → open <a href="{{ $tasksUrl }}">My content tasks</a>.</li>
    <li>Open each chapter task above and review class, chapter, and offered rate.</li>
    <li>Click <strong>I agree — start work</strong> only if you accept the rate.</li>
    @if ($tasks->contains(fn ($task) => $task->isFillBlankConversion()) && ! $tasks->contains(fn ($task) => ! $task->isFillBlankConversion()))
        <li>Open Convert, skip number-names, edit each blank, then <strong>Check as a student</strong>.</li>
        <li>Submit when every included blank is Checked. Admin publishes fill-in-blank and written.</li>
    @else
        <li>Open the textbook chapter page → upload / import MCQs (JSON or zip with diagrams).</li>
        <li>Mark upload complete, then verify <strong>every question</strong> (text, options, correct answer, hint, explanation, difficulty, diagram).</li>
        <li>Submit for admin publish when verification is complete. Admin publishes after review.</li>
    @endif
</ol>

@if ($guideUrl)
    <p>
        Screen-wise guide:
        <a href="{{ $guideUrl }}">{{ $guideUrl }}</a>
    </p>
@endif

<p>
    Login: <a href="{{ $loginUrl }}">{{ $loginUrl }}</a><br>
    All my tasks: <a href="{{ $tasksUrl }}">{{ $tasksUrl }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
