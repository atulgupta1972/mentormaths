<p>Hello,</p>

<p>
    <strong>{{ $task->assignee->name }}</strong> submitted
    <strong>Ch {{ $task->textbookChapter->chapter_number }} — {{ $task->textbookChapter->title }}</strong>
    ({{ $task->textbookChapter->textbook->gradeLevel->name ?? 'Class' }})
    for admin publish.
</p>

<p>
    All questions have been verified. Please review and publish from the admin dashboard:<br>
    <a href="{{ route('admin.content-tasks.index') }}">{{ route('admin.content-tasks.index') }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
