<p>Hello {{ $summary['recipient_name'] }},</p>

<p>
    AI extraction is complete for
    <strong>{{ $summary['grade_name'] }} · {{ $summary['book_name'] }}</strong>,
    Ch {{ $summary['chapter_number'] }} — {{ $summary['chapter_title'] }}.
</p>

<p>
    <strong>{{ $summary['items_count'] }}</strong>
    question{{ $summary['items_count'] === 1 ? '' : 's' }}
    {{ $summary['items_count'] === 1 ? 'is' : 'are' }}
    ready for your review.
</p>

<p>
    Open the chapter to check answers, edit wording, and publish MCQ + written sets:<br>
    <a href="{{ $summary['review_url'] }}">{{ $summary['review_url'] }}</a>
</p>

<p>
    All textbook chapters:<br>
    <a href="{{ $summary['index_url'] }}">{{ $summary['index_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
