<p>Hello {{ $registrationRequest->parent1_name }},</p>

<p>Good news — the registration for <strong>{{ $registrationRequest->student_name }}</strong> has been approved.</p>

<p>You can log in using these details:</p>

<ul>
    <li><strong>Login page:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a></li>
    <li><strong>Email:</strong> {{ $loginEmail }}</li>
    <li><strong>Password:</strong> {{ $loginPassword }}</li>
</ul>

<p>Please change the password after your first login.</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
