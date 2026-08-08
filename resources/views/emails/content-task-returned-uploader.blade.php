<p>Hello,</p>

<p>Admin has sent your textbook MCQ chapter back for <strong>re-verification</strong>.</p>

<p>Please open the task, review each question, fix the <strong>correct option</strong> and <strong>explanation</strong> where needed, then save each question and submit again when done.</p>

@if($task->admin_notes)
<p><strong>Admin note:</strong><br>{!! nl2br(e($task->admin_notes)) !!}</p>
@endif

<p><a href="{{ $taskUrl }}">Open chapter task →</a></p>

<p>If you are not logged in, <a href="{{ $loginUrl }}">sign in here</a> first.</p>

<p>— Mentor Maths</p>
