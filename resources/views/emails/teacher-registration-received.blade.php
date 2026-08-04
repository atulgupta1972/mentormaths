<p>Hi {{ $request->name }},</p>

<p>Thank you for applying to join Mentor Maths as a teacher / question creator.</p>

<p>We have received your application and will review it shortly. If we need to discuss rates or availability, we will send you a counter offer by email.</p>

<p>You applied for:</p>
<ul>
    @if ($request->interested_in_content_creation)
        <li>Creating question bank &amp; test papers</li>
    @endif
    @if ($request->interested_in_doubt_solving)
        <li>Online doubt solving ({{ $request->doubt_sessions_per_week }}× per week, ₹{{ number_format($request->proposed_hourly_rate_inr ?? 0) }}/hour proposed)</li>
    @endif
</ul>

<p>— Mentor Maths</p>
