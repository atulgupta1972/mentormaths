<p>Hello,</p>

<p>
    <strong>{{ $studentName }}</strong> has submitted
    <strong>{{ $summary['set_code'] }}</strong>
    ({{ $summary['tier_label'] }} {{ $summary['kind_label'] }}).
</p>

@if ($summary['scope_line'])
    <p>{{ $summary['scope_line'] }}</p>
@endif

<p>
    <strong>This submission:</strong> {{ $summary['attempt_label'] }}<br>
    <strong>Score:</strong> {{ $summary['score_label'] }}<br>
    <strong>Time taken:</strong> {{ $summary['time_label'] }}<br>
    <strong>Submitted:</strong> {{ $summary['completed_label'] ?? '—' }}<br>
    @if ($summary['target_label'])
        <strong>Target date was:</strong> {{ $summary['target_label'] }}<br>
    @endif
    <strong>Timing:</strong> {{ $summary['submission_timing_label'] }}
</p>

@if (count($summary['attempt_history']) > 1)
    <p><strong>All attempts on this assignment:</strong></p>
    <table cellpadding="6" cellspacing="0" border="1" style="border-collapse: collapse; font-size: 14px;">
        <thead>
            <tr>
                <th align="left">Attempt</th>
                <th align="left">Score</th>
                <th align="left">Time</th>
                <th align="left">Submitted</th>
                <th align="left">Timing</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($summary['attempt_history'] as $attemptRow)
                <tr>
                    <td>
                        #{{ $attemptRow['attempt_number'] }}
                        @if ($attemptRow['is_current'])
                            (just submitted)
                        @endif
                    </td>
                    <td>{{ $attemptRow['score_label'] }}</td>
                    <td>{{ $attemptRow['time_label'] }}</td>
                    <td>{{ $attemptRow['completed_label'] ?? '—' }}</td>
                    <td>{{ $attemptRow['submission_timing_label'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($summary['is_guided'])
    <p>
        <strong>Guided practice breakdown (this attempt):</strong><br>
        {{ $summary['first_try_correct'] ?? 0 }} correct on first try ·
        {{ $summary['corrected_after_help'] ?? 0 }} corrected after help ·
        {{ $summary['given_up'] ?? 0 }} gave up ·
        {{ $summary['help_asked_count'] ?? 0 }} used method help
    </p>
@endif

@if (count($summary['wrong_questions']) > 0)
    <p><strong>What went wrong (needs review):</strong></p>
    <ul>
        @foreach ($summary['wrong_questions'] as $question)
            <li>
                <strong>Q{{ $question['number'] }}</strong> — {{ $question['outcome_label'] }}
                @if (! empty($question['help_asked_label']))
                    <br>{{ $question['help_asked_label'] }}
                @endif
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
    <p><strong>No mistakes — all questions answered correctly.</strong></p>
@endif

<p>
    View full details in admin:<br>
    <a href="{{ $summary['admin_url'] }}">{{ $summary['admin_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
