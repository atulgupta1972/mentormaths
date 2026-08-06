<p>Hi {{ $request->name }},</p>

<p>Your Mentor Maths application has been <strong>approved</strong>.</p>

<p>You can log in with these details:</p>

<ul>
    <li><strong>Login page:</strong> <a href="{{ $loginUrl }}">{{ $loginUrl }}</a></li>
    <li><strong>Email (login ID):</strong> {{ $request->email }}</li>
    <li><strong>Password:</strong> Use the password you chose when registering.</li>
</ul>

@if ($assignMentor || $assignContentUploader)
    <p><strong>Your roles:</strong></p>
    <ul>
        <li>Teacher</li>
        @if ($assignMentor)
            <li>Mentor — help students with doubt resolution</li>
        @endif
        @if ($assignContentUploader)
            <li>Content uploader — upload and verify textbook MCQ chapters</li>
        @endif
    </ul>
@endif

@if ($assignContentUploader)
    <p>
        After login, open <strong>My content tasks</strong> to see assigned chapters, agree to rates, upload MCQs, and verify questions:<br>
        <a href="{{ $contentTasksUrl }}">{{ $contentTasksUrl }}</a>
    </p>
@endif

@if ($request->agreedHourlyRateInr())
    <p>Agreed doubt-solving rate: ₹{{ number_format($request->agreedHourlyRateInr()) }}/hour.</p>
@endif

@if ($request->admin_notes)
    <p><strong>Message from Mentor Maths:</strong></p>
    <p>{{ $request->admin_notes }}</p>
@endif

<p>— Mentor Maths</p>
