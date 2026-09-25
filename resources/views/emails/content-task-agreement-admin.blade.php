<p>Hello,</p>

<p>
    <strong>{{ $task->assignee->name }}</strong> ({{ $task->assignee->email }})
    agreed to
    @if ($task->isConceptPathBuild())
        build the <strong>concept path</strong> for
    @elseif ($task->isFillBlankConversion())
        convert fill-in-blanks for
    @else
        upload MCQs for
    @endif
    <strong>Ch {{ $task->textbookChapter->chapter_number }} — {{ $task->textbookChapter->title }}</strong>
    for <strong>₹{{ number_format($task->agreed_amount_inr) }}</strong>.
</p>

@if ($task->duplicate_override_reason)
    <p>
        <strong>Duplicate override:</strong> {{ $task->duplicate_override_reason }}
    </p>
@endif

<p>
    @if ($task->isConceptPathBuild())
        They will build cards, approve the flow, then Run concepts to complete.
    @else
        They can now begin work on this chapter.
    @endif
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
