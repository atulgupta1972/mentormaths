<p>Hello {{ $studentName }},</p>

<p>
    We received your upload for <strong>{{ $summary['set_code'] }}</strong>
    ({{ $summary['kind_label'] }}).
</p>

<p>
    Automatic checking could not finish for this upload. Your teacher can mark it manually — you do not need to upload again unless your teacher asks.
</p>

@if (! empty($summary['grading_error']))
    <p><strong>Note:</strong> {{ $summary['grading_error'] }}</p>
@endif

<p>
    You can continue with your other work on the dashboard:<br>
    <a href="{{ $summary['dashboard_url'] }}">{{ $summary['dashboard_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
