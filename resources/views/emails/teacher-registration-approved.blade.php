<p>Hi {{ $request->name }},</p>

<p>Your Mentor Maths teacher application has been <strong>approved</strong>.</p>

<p>You can log in with the email and password you chose when registering:</p>
<p><a href="{{ route('login') }}">{{ route('login') }}</a></p>

@if ($request->agreedHourlyRateInr())
    <p>Agreed doubt-solving rate: ₹{{ number_format($request->agreedHourlyRateInr()) }}/hour.</p>
@endif

<p>— Mentor Maths</p>
