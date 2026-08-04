<p>{{ $request->name }} has <strong>{{ $request->offer_response }}</strong> the counter offer of ₹{{ number_format($request->counter_hourly_rate_inr ?? 0) }}/hour.</p>

<p><a href="{{ route('admin.teacher-registrations.show', $request) }}">Review and approve</a></p>
