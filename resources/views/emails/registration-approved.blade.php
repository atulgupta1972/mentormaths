<p>Hello {{ $registrationRequest->parent1_name }},</p>

<p>Good news — the registration for <strong>{{ $registrationRequest->student_name }}</strong> has been approved.</p>

<p>You can log in using these details:</p>

<ul>
    <li><strong>Login page:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a></li>
    <li><strong>Email:</strong> {{ $loginEmail }}</li>
    @if ($loginPassword)
        <li><strong>Password:</strong> {{ $loginPassword }}</li>
    @else
        <li><strong>Password:</strong> Use the password you chose when you registered.</li>
    @endif
</ul>

@if ($loginPassword)
    <p>Please change the password after your first login.</p>
@endif

<p>Thank you,<br>{{ config('app.name') }}</p>
