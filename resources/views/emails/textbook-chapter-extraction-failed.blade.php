<p>Hello {{ $summary['recipient_name'] }},</p>

<p>
    AI extraction could not finish for
    <strong>{{ $summary['grade_name'] }} · {{ $summary['book_name'] }}</strong>,
    Ch {{ $summary['chapter_number'] }} — {{ $summary['chapter_title'] }}.
</p>

<p><strong>Error:</strong> {{ $summary['error_message'] }}</p>

<p>
    Open the chapter to try again (Re-extract PDF):<br>
    <a href="{{ $summary['review_url'] }}">{{ $summary['review_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
