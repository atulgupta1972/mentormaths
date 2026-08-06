<p>Hi {{ $uploader->name }},</p>

<p>
    You have been assigned
    <strong>{{ $tasks->count() }}</strong>
    textbook chapter{{ $tasks->count() === 1 ? '' : 's' }} on Mentor Maths.
    Please log in and <strong>carefully review the assignment in our system</strong> before you start work.
</p>

<p><strong>Your steps:</strong></p>
<ol>
    <li>Open <a href="{{ $tasksUrl }}">My content tasks</a> and read each assignment.</li>
    <li>Check the offered rate and any admin notes below.</li>
    <li>Click <strong>I agree — start work</strong> only after you accept the rate.</li>
    <li>Upload MCQs for the chapter, then verify <strong>every question</strong> in the checklist (text, options, correct answer, hint, explanation, difficulty, diagram).</li>
    <li>Submit for admin publish when all questions are verified.</li>
</ol>

<p><strong>Assigned chapters:</strong></p>
<ul>
    @foreach ($tasks as $task)
        @php($chapter = $task->textbookChapter)
        <li>
            {{ $chapter->textbook->gradeLevel->name ?? 'Class' }}
            · Ch {{ $chapter->chapter_number }} — {{ $chapter->title }}
            · <strong>₹{{ number_format($task->offered_amount_inr) }}</strong>
        </li>
    @endforeach
</ul>

@if ($tasks->first()?->admin_notes)
    <p><strong>Note from admin:</strong> {{ $tasks->first()->admin_notes }}</p>
@endif

<p>
    Login: <a href="{{ $loginUrl }}">{{ $loginUrl }}</a><br>
    My content tasks: <a href="{{ $tasksUrl }}">{{ $tasksUrl }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
