<p>Hello {{ $recipientName }},</p>

<p>Your Mentor Maths trial access is ready. No admin approval is required.</p>

<ul>
    <li><strong>Login page:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a></li>
    <li><strong>Email:</strong> {{ $loginEmail }}</li>
    <li><strong>Access code (tcode):</strong> {{ $plainCode }}</li>
    @if ($expiresOn)
        <li><strong>Valid until:</strong> {{ $expiresOn }}</li>
    @endif
</ul>

<p>Use your <strong>email</strong> and <strong>tcode</strong> (as password) to log in. You can also enter the tcode alone in the email field.</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
