<p>Hello,</p>

<p>
    We noticed that <strong>{{ $studentName }}</strong>
    @if ($gradeName)
        ({{ $gradeName }})
    @endif
    has not marked a <strong>school study plan</strong> in Mentor Maths yet.
</p>

<p>
    Please log in and mark which chapters have already been studied in school, and which chapter is currently
    <strong>under study</strong>. This helps us assign the right practice and exam prep.
</p>

<p>
    Open My Study Plan:<br>
    <a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a>
</p>

<p>
    Steps after login:
</p>
<ol>
    <li>Open the dashboard / My Study Plan.</li>
    <li>Tick chapters already covered in school as <strong>Studied</strong>.</li>
    <li>Mark the chapter being taught now as <strong>Under study</strong>.</li>
</ol>

<p>Thank you,<br>{{ config('app.name') }}</p>
