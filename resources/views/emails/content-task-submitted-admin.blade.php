<p>Hello,</p>

<p>
    <strong>{{ $task->assignee->name }}</strong>
    @if ($task->isConceptPathBuild())
        completed the <strong>concept builder</strong> job (approved path + full Run) for
    @elseif ($task->isFillBlankConversion())
        submitted fill-in-blank conversion for
    @else
        submitted
    @endif
    <strong>Ch {{ $task->textbookChapter->chapter_number }} — {{ $task->textbookChapter->title }}</strong>
    ({{ $task->textbookChapter->textbook->gradeLevel->name ?? 'Class' }})
    @unless ($task->isConceptPathBuild())
        for admin publish
    @endunless
    .
</p>

<p>
    @if ($task->isConceptPathBuild())
        Payable amount: <strong>₹{{ number_format($task->payableAmountInr()) }}</strong>.
        Review / mark paid from Finance or Content tasks:<br>
    @else
        All questions have been verified. Please review and publish from the admin dashboard:<br>
    @endif
    <a href="{{ route('admin.content-tasks.index') }}">{{ route('admin.content-tasks.index') }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
