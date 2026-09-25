<p>Hi {{ $uploader->name }},</p>

<p>
    You have been assigned
    <strong>{{ $tasks->count() }}</strong>
    textbook chapter{{ $tasks->count() === 1 ? '' : 's' }} for
    <strong>
        @if ($allConcept)
            concept builder
        @elseif ($allFillBlank)
            fill-in-blank conversion
        @else
            content upload (MCQ)
        @endif
    </strong>
    on Mentor Maths.
</p>

<p>
    @if ($allConcept)
        <strong>Work type:</strong> Concept builder (teaching cards for the chapter).
        Not MCQ upload. Build the concept path from the chapter PDF, approve it, then
        <strong>Run</strong> the full path end-to-end to complete the job and earn the offered rate.
    @elseif ($allFillBlank)
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
            @if ($task->isConceptPathBuild())
                Build concept cards for this chapter → Approve → Run the full concept path (mandatory).
            @elseif ($task->isFillBlankConversion())
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
    <li>Log in → open <a href="{{ $tasksUrl }}">My content tasks</a> (look for the <strong>Concept builder</strong> section if this is a concept job).</li>
    <li>Open each chapter task above and review class, chapter, and offered rate.</li>
    <li>Click <strong>I agree — start work</strong> only if you accept the rate.</li>
    @if ($allConcept)
        <li>Open <strong>Build concepts</strong> for the chapter (Concept path editor).</li>
        <li>Create / edit teaching cards from the PDF, attach figures, then <strong>Approve concept flow</strong>.</li>
        <li>Click <strong>Run concepts</strong> and walk through <em>every</em> card to the end — that finishes the job for pay.</li>
        <li>Do one concept chapter at a time before starting the next assigned concept job.</li>
    @elseif ($allFillBlank)
        <li>Open Convert, skip number-names, edit each blank, then <strong>Check as a student</strong>.</li>
        <li>Submit when every included blank is Checked. Admin publishes fill-in-blank and written.</li>
    @else
        <li>Open the textbook chapter page → upload / import MCQs (JSON or zip with diagrams).</li>
        <li>Mark upload complete, then verify <strong>every question</strong> (text, options, correct answer, hint, explanation, difficulty, diagram).</li>
        <li>Submit for admin publish when verification is complete. Admin publishes after review.</li>
    @endif
</ol>

@if ($guideUrl && ! $allConcept)
    <p>
        Screen-wise guide:
        <a href="{{ $guideUrl }}">{{ $guideUrl }}</a>
    </p>
@endif

<p>
    Login: <a href="{{ $loginUrl }}">{{ $loginUrl }}</a><br>
    All my tasks: <a href="{{ $tasksUrl }}">{{ $tasksUrl }}</a>
    @if ($primaryTaskUrl)
        <br>This assignment: <a href="{{ $primaryTaskUrl }}">{{ $primaryTaskUrl }}</a>
    @endif
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
