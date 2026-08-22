<p>Hello {{ $studentName }},</p>

<p>
    Your teacher checked the sum you reported as misprint / incomplete on
    <strong>{{ config('app.name') }}</strong>.
</p>

<p>
    <strong>The question is correct.</strong> Please re-attempt it from your correction / revise list.
</p>

<p style="margin:16px 0; padding:12px; background:#f8fafc; border:1px solid #e2e8f0;">
    @if ($setCode)
        <strong>Set:</strong> {{ $setCode }}<br>
    @endif
    <strong>Type:</strong> {{ $contextLabel }}<br>
    <strong>Question:</strong> {{ $questionPreview }}
</p>

<p>
    <strong>Important:</strong> This sum scores <strong>0 marks</strong> on the original attempt —
    even if you get it right when you re-attempt. Re-attempting is for learning and clearing the revise queue.
</p>

<p>
    <a href="{{ $dashboardUrl }}">Open your dashboard</a>
    · <a href="{{ $loginUrl }}">Log in</a>
</p>

<p>Mentor Maths</p>
