<p>Hello,</p>

<p>
    Admin reviewed your chapter and marked it <strong>{{ $decisionLabel }}</strong>.
    The questions you already uploaded stay on this chapter.
    Please add <strong>another book</strong> for the same chapter.
</p>

<p>
    Open the task, choose the other book, then upload that book's PDF and questions.
    You are paid <strong>once for the whole chapter</strong>, not again for the second book.
    Admin will publish both books together.
</p>

@if(filled($note))
<p><strong>Admin note:</strong><br>{!! nl2br(e($note)) !!}</p>
@endif

<p><a href="{{ $taskUrl }}">Open chapter task →</a></p>

<p>If you are not logged in, <a href="{{ $loginUrl }}">sign in here</a> first.</p>

<p>— Mentor Maths</p>
