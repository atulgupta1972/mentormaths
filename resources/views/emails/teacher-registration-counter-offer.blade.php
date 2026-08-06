<p>Hi {{ $request->name }},</p>

<p>Thank you for your interest in doubt-solving with Mentor Maths.</p>

<p>
    Your proposed rate: <strong>₹{{ number_format($request->proposed_hourly_rate_inr ?? 0) }}/hour</strong><br>
    Our counter offer: <strong>₹{{ number_format($request->counter_hourly_rate_inr ?? 0) }}/hour</strong>
</p>

@if ($request->counter_offer_message)
    <p><strong>Message from Mentor Maths:</strong></p>
    <p>{{ $request->counter_offer_message }}</p>
@endif

<p>
    <a href="{{ route('teacher-registration.offer', $request->counter_offer_token) }}">Accept or decline this offer</a>
</p>

<p>— Mentor Maths</p>
