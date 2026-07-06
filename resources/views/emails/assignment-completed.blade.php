<p>Hello,</p>

<p>
    <strong>{{ $studentName }}</strong> has finished
    <strong>{{ $summary['set_code'] }}</strong>
    ({{ $summary['tier_label'] }} {{ $summary['kind_label'] }}).
</p>

@if ($summary['scope_line'])
    <p>{{ $summary['scope_line'] }}</p>
@endif

<p>
    <strong>Score:</strong> {{ $summary['score_label'] }}<br>
    <strong>Time taken:</strong> {{ $summary['time_label'] }}<br>
    <strong>Submitted:</strong> {{ $summary['completed_label'] ?? '—' }}<br>
    @if ($summary['target_label'])
        <strong>Target date was:</strong> {{ $summary['target_label'] }}<br>
    @endif
    <strong>Timing:</strong> {{ $summary['submission_timing_label'] }}
</p>

@if ($summary['is_guided'])
    <p>
        <strong>Guided practice breakdown:</strong>
        {{ $summary['first_try_correct'] ?? 0 }} correct on first try ·
        {{ $summary['corrected_after_help'] ?? 0 }} corrected after help ·
        {{ $summary['given_up'] ?? 0 }} given up
    </p>
@endif

@if (count($summary['wrong_questions']) > 0)
    <p><strong>Where help is needed:</strong></p>
    <ul>
        @foreach ($summary['wrong_questions'] as $question)
            <li>
                Q{{ $question['number'] }} — {{ $question['outcome_label'] }}
                @if ($question['topic_name'])
                    <br>Topic: {{ $question['topic_name'] }}
                    @if ($question['chapter_name'])
                        ({{ $question['chapter_name'] }})
                    @endif
                @elseif ($question['chapter_name'])
                    <br>Chapter: {{ $question['chapter_name'] }}
                @endif
            </li>
        @endforeach
    </ul>
@else
    <p><strong>All questions answered correctly on first try.</strong></p>
@endif

<p>
    View the dashboard for full details:<br>
    <a href="{{ $dashboardUrl }}">{{ $dashboardUrl }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
