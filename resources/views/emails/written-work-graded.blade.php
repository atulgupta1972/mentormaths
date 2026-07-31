<p>Hello {{ $studentName }},</p>

<p>
    Your written work for <strong>{{ $summary['set_code'] }}</strong>
    ({{ $summary['kind_label'] }}) has been checked.
</p>

@if ($summary['chapter_name'] || $summary['topic_name'])
    <p>
        @if ($summary['chapter_name'])
            Chapter: {{ $summary['chapter_name'] }}<br>
        @endif
        @if ($summary['topic_name'])
            Topic: {{ $summary['topic_name'] }}
        @endif
    </p>
@endif

<p>
    <strong>Score:</strong> {{ $summary['score_label'] }}<br>
    @if ($summary['target_label'])
        <strong>Target date was:</strong> {{ $summary['target_label'] }}<br>
    @endif
    @if ($summary['handwriting_label'])
        <strong>Handwriting:</strong> {{ $summary['handwriting_label'] }}<br>
    @endif
</p>

@if ($summary['teacher_remarks'])
    <p><strong>Teacher remarks:</strong> {{ $summary['teacher_remarks'] }}</p>
@elseif ($summary['ai_summary'])
    <p><strong>Feedback:</strong> {{ $summary['ai_summary'] }}</p>
@endif

@if (($summary['wrong_count'] ?? 0) > 0)
    <p>You had {{ $summary['wrong_count'] }} question{{ $summary['wrong_count'] === 1 ? '' : 's' }} to review. Open the result to see correct answers.</p>
@else
    <p><strong>All questions correct — well done.</strong></p>
@endif

<p>
    View your result:<br>
    <a href="{{ $summary['view_url'] }}">{{ $summary['view_url'] }}</a>
</p>

<p>
    Dashboard:<br>
    <a href="{{ $summary['dashboard_url'] }}">{{ $summary['dashboard_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
