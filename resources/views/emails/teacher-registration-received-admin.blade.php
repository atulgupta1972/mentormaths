<p>New mentor application from <strong>{{ $request->name }}</strong> ({{ $request->email }}).</p>

<p>
    Question bank creation: {{ $request->interested_in_content_creation ? 'Yes' : 'No' }}<br>
    Book content upload: {{ $request->interested_in_book_content_upload ? 'Yes' : 'No' }}
    @if ($request->interested_in_book_content_upload)
        — proposed ₹{{ number_format($request->proposed_rate_per_set_inr ?? 0) }}/set
    @endif<br>
    Online mentoring: {{ $request->interested_in_doubt_solving ? 'Yes' : 'No' }}
    @if ($request->interested_in_doubt_solving)
        — proposed ₹{{ number_format($request->proposed_hourly_rate_inr ?? 0) }}/hour
        @if ($request->agreed_to_mentoring_program)
            · accepted mentoring model
        @endif
    @endif
    @if ($request->resume_path)
        <br>Resume uploaded: yes
    @endif
</p>

<p><a href="{{ route('admin.teacher-registrations.show', $request) }}">Review application</a></p>
