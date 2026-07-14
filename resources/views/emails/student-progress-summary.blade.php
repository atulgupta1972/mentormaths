<p>Hello,</p>

<p>
    Here is the progress summary for <strong>{{ $studentName }}</strong>
    @if ($summary['class_name'])
        ({{ $summary['class_name'] }})
    @endif
    as on <strong>{{ $summary['as_of_label'] }}</strong>.
</p>

@if ($summary['period_label'])
    <p><strong>Period covered:</strong> {{ $summary['period_label'] }}</p>
@endif

@if (($summary['stats']['overall_score_label'] ?? null) && ($summary['stats']['completed_count'] ?? 0) > 0)
    <p><strong>Overall score:</strong> {{ $summary['stats']['overall_score_label'] }}</p>
@endif

<p>
    <strong>Completed:</strong> {{ $summary['stats']['completed_count'] }} ·
    <strong>Pending:</strong> {{ $summary['stats']['pending_count'] }} ·
    <strong>Overdue:</strong> {{ $summary['stats']['overdue_count'] }} ·
    <strong>Need teacher help:</strong> {{ $summary['stats']['help_count'] }}
</p>

@if (count($summary['completed']) > 0)
    <p><strong>Completed work:</strong></p>
    <ul>
        @foreach ($summary['completed'] as $row)
            <li>
                <strong>{{ $row['set_code'] }}</strong>
                — {{ $row['latest_score_label'] ?? \App\Support\ScoreLabel::format($row['latest_score'] ?? null, $row['latest_max_score'] ?? null) ?? '—' }}
                ({{ $row['kind_label'] }})
                @if (($row['latest_attempt_number'] ?? 0) > 1)
                    · Attempt {{ $row['latest_attempt_number'] }}
                @endif
                @if (count($row['review_items'] ?? []) > 0)
                    <br>Needs review:
                    <ul>
                        @foreach ($row['review_items'] as $item)
                            <li>
                                {{ $item['label'] }}
                                @if ($item['help_asked_label'])
                                    — {{ $item['help_asked_label'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    <br>All correct — no review needed.
                @endif
            </li>
        @endforeach
    </ul>
@endif

@if (count($summary['overdue']) > 0)
    <p><strong>Overdue:</strong></p>
    <ul>
        @foreach ($summary['overdue'] as $row)
            <li>
                {{ $row['set_code'] }} — target {{ $row['target_date'] ? \App\Support\DateLabels::formatDate($row['target_date']) : '—' }}
            </li>
        @endforeach
    </ul>
@endif

@if (count($summary['pending']) > 0)
    <p><strong>Pending:</strong></p>
    <ul>
        @foreach ($summary['pending'] as $row)
            <li>
                {{ $row['set_code'] }} — target {{ $row['target_date'] ? \App\Support\DateLabels::formatDate($row['target_date']) : '—' }}
            </li>
        @endforeach
    </ul>
@endif

@if (count($summary['help_requests']) > 0)
    <p><strong>Asked for teacher help:</strong></p>
    <ul>
        @foreach ($summary['help_requests'] as $item)
            <li>
                @if ($item['set_code'])
                    {{ $item['set_code'] }} —
                @endif
                {{ $item['question_text'] ?? 'Needs explanation in class' }}
            </li>
        @endforeach
    </ul>
@endif

@if (count($summary['recently_completed'] ?? []) > 0 && ($summary['period_label'] ?? null))
    <p><strong>Completed in this period:</strong></p>
    <ul>
        @foreach ($summary['recently_completed'] as $row)
            <li>{{ $row['set_code'] }} — {{ $row['latest_score_label'] ?? \App\Support\ScoreLabel::format($row['latest_score'] ?? null, $row['latest_max_score'] ?? null) ?? '—' }}</li>
        @endforeach
    </ul>
@endif

<p>
    View dashboard:<br>
    <a href="{{ $summary['dashboard_url'] }}">{{ $summary['dashboard_url'] }}</a>
</p>

<p>Thank you,<br>{{ config('app.name') }}</p>
