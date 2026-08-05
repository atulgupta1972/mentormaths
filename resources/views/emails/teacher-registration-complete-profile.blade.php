<p>Hi {{ $request->name }},</p>

<p>Thank you for applying to join the Mentor Maths mentor pool.</p>

<p>We need a few profile details to complete your application:</p>

<ul>
    @foreach ($request->missingProfileFields() as $field)
        <li>{{ \App\Models\TeacherRegistrationRequest::missingProfileFieldLabel($field) }}</li>
    @endforeach
</ul>

@if ($request->profile_completion_message)
    <p>{{ $request->profile_completion_message }}</p>
@endif

<p>
    <a href="{{ route('teacher-registration.profile', $request->profile_completion_token) }}">Complete your location &amp; language details</a>
</p>

<p>This takes about one minute. Default country is India — please add city, state, and the languages you teach in (English, Hindi, and regional language if any).</p>

<p>— Mentor Maths</p>
