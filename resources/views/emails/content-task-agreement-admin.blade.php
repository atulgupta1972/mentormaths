<p>Hello,</p>

<p>
    <strong>{{ $task->assignee->name }}</strong> ({{ $task->assignee->email }})
    agreed to upload
    <strong>Ch {{ $task->textbookChapter->chapter_number }} — {{ $task->textbookChapter->title }}</strong>
    for <strong>₹{{ number_format($task->agreed_amount_inr) }}</strong>.
</p>

@if ($task->duplicate_override_reason)
    <p>
        <strong>Duplicate override:</strong> {{ $task->duplicate_override_reason }}
    </p>
@endif

<p>They can now begin work on this chapter.</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
